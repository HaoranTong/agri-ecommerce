# 礼品卡购卡流程后端改造说明

> 更新时间：2025-11-28
> 适用版本：MyShop Core 插件 v2.x 及以后

## 1. 模板扩展字段

`wp_myshop_gift_card_templates` 新增字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `purchase_flow` | varchar(32) | `stored_value`（储值）、`bundle`（固定组合）、`custom`（任意组合）。默认 `stored_value`。|
| `amount_options` | text/json | 储值卡可选金额列表（示例 `[100,200,500]`）。为空则允许自定义金额。|
| `min_amount` / `max_amount` | decimal | 储值卡自定义金额上下限。|
| `allowed_product_ids` | text/json | 任意组合允许的商品 ID 列表；为空表示全部。|
| `allowed_variation_ids` | text/json | 任意组合允许的变体 ID，优先于 `allowed_product_ids`。|
| `max_items` | int | 任意组合可选 SKU 数量上限。|
| `max_total` | decimal | 任意组合金额上限。|
| `success_copywriting` | text | 购卡成功提示文案，前端可直接展示。|

> 配置页面需增加对应输入框，保存/读取时统一使用 JSON（`wp_json_encode/ wp_json_decode`）。

## 2. 订单接口扩展

`POST /orders` 请求体新增可选字段：

```json
{
  "giftcard_mode": "stored_value | bundle | custom",
  "giftcard_template_id": 12,
  "giftcard_payload": {
    "amount": 200,
    "selected_items": [
      { "variation_id": 123, "quantity": 2 },
      { "variation_id": 456, "quantity": 1 }
    ],
    "message": "送你一套礼品",
    "recipient_hint": "王小明"
  }
}
```

处理逻辑：
1. 当 `giftcard_mode` 不为空时，`shipping_address` 可选；订单自动打上 `order_type = giftcard` 元数据。
2. 校验模板存在且 `purchase_flow` 与 `giftcard_mode` 一致。
3. 根据模式执行额外校验：
   - `stored_value`：金额需落在模板约束内。
   - `bundle`：同步模板 `bundle_items`，后续生成礼品卡时作为权益快照。
   - `custom`：校验 `selected_items` 是否在允许 SKU 列表内，并检查数量/金额上限。

## 3. 支付完成后的礼品卡生成

- 监听订单状态从 `pending/processing` → `completed` 或支付凭证审核通过的钩子（`woocommerce_order_status_completed` + 管理端审批逻辑）。
- 若订单具备 `giftcard_mode` 元数据，则：
  1. 调用 `Gift_Card_Controller::purchase_card_from_order($order)` 新增礼品卡。
  2. 生成卡号、PIN、有效期，与模板 `delivery_modes` 同步。
  3. 将 `card_number`、`card_pin` 写入订单备注及 `order_meta`，便于后台查阅。
  4. 推送一条通知给小程序（可通过轮询 `/gift-cards` 刷新）。

### `purchase_card_from_order` 伪代码

```php
public static function purchase_card_from_order( $order ) {
    $mode     = $order->get_meta('giftcard_mode');
    $template = self::get_template_row( $order->get_meta('giftcard_template_id') );

    $payload  = $order->get_meta('giftcard_payload') ?: [];
    $amount   = $mode === 'stored_value'
        ? ($payload['amount'] ?? $template->fixed_amount)
        : $template->fixed_amount;

    return self::create_card([
        'template'        => $template,
        'amount'          => $amount,
        'selected_items'  => $payload['selected_items'] ?? [],
        'message'         => $payload['message'] ?? '',
        'recipient_hint'  => $payload['recipient_hint'] ?? '',
        'purchaser_id'    => $order->get_user_id(),
        'order_id'        => $order->get_id(),
    ]);
}
```

## 4. 新/调整的 REST 接口

| 接口 | 方法 | 描述 |
| --- | --- | --- |
| `/gift-cards/templates` | GET | 响应中补充新字段：`purchase_flow`、`amount_options`、`min_amount`、`max_amount`、`allowed_product_ids`、`allowed_variation_ids`、`max_items`、`max_total`、`success_copywriting`。|
| `/gift-cards/purchase` | POST | 仅限后台/储值卡快速购卡使用；需要新增参数 `order_id` 以便追溯。|
| `/gift-cards/orders/<orderId>/issue` | POST | （可选）管理员手动补发礼品卡。|
| `/gift-cards/templates/<id>` | GET | 返回单个模板详情，包含上述全部扩展字段。|

## 5. 数据落库约定

- `wp_myshop_gift_cards` 新增字段：
  - `order_id`（int）：来源订单。
  - `mode`（varchar）：`stored_value/bundle/custom`。
  - `bundle_snapshot`（longtext）：固定组合或自选组合的 SKU 明细快照。
  - `message` / `recipient_hint`（text）：赠言及受赠人提示。
- `wp_myshop_gift_card_redemptions` 需支持 `bundle/custom` 类型，在兑换时将快照生成实际发货订单或兑换记录。

## 6. 任务与排期建议

1. **阶段一 – 数据结构与模板管理**
   - 数据库迁移脚本，后台表单更新，REST 输出字段同步。
2. **阶段二 – 订单与发卡逻辑**
   - 扩展 `/orders`、完成 `purchase_card_from_order`、打通支付后自动发卡。
3. **阶段三 – 兑换逻辑对齐**
   - 根据 `mode` 区分兑换策略，确保固定组合/任意组合能按快照发货。
4. **阶段四 – 运维/工具**
   - 提供 WP-CLI 命令与后台工具，便于运营查看礼品卡订单、手动补发、导出统计。

## 7. 测试要点

- `stored_value`：不同金额、凭证审核路径，确认卡号入库并在“我的礼品卡”可见。
- `bundle`：下单后自动生成礼品卡，快照内容与模板一致。
- `custom`：受限 SKU/金额时的校验提示，支付成功后生成卡号并带自选快照。
- 订单退款/取消时的处理策略（是否回收礼品卡）需在迭代中明确。

---

如需在接口或数据结构上进一步扩展，请在提 PR 前更新本说明并同步前端团队。