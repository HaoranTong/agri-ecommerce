# 微信支付开发环境调试说明

## 问题现象

在开发环境（微信开发者工具 + 本地Laragon）中进行支付测试时：
- ✅ 扫码支付成功（微信扫码支付完成）
- ❌ WordPress后台订单状态仍为"待付款"
- ❌ 前端订单状态仍为"待支付"

## 根本原因

**微信支付回调无法到达本地开发环境**

### 回调URL说明

```php
// payment-controller.php 创建支付时设置的回调URL
$notify_url = home_url('/wp-json/myshop/v1/payments/notify/wechat');
```

在不同环境下的值：
- 开发环境：`https://dev.fanbaoer.com/wp-json/myshop/v1/payments/notify/wechat`
- 体验版：`https://staging.fanbaoer.com/wp-json/myshop/v1/payments/notify/wechat`
- 正式版：`https://fanbaoer.com/wp-json/myshop/v1/payments/notify/wechat`

### 为什么开发环境收不到回调？

1. **Cloudflare Tunnel限制**
   - `dev.fanbaoer.com` 是通过Cloudflare Tunnel映射到本地 `127.0.0.1:8080`
   - Cloudflare Tunnel可能对POST请求有限制或延迟
   - 微信服务器回调时可能超时或被拦截

2. **本地环境不稳定**
   - Laragon本地服务可能重启
   - Cloudflare Tunnel连接可能中断
   - 网络波动导致回调失败

3. **微信支付回调特性**
   - 微信支付成功后，会异步向notify_url发送POST请求
   - 如果回调失败，微信会重试多次（间隔递增）
   - 但重试也可能全部失败

## 解决方案

### 方案1：前端主动查询支付状态（已实现）

```typescript
// src/pages/order/payment.tsx
await Taro.requestPayment(paymentOption);

// 支付成功后延迟2秒主动查询
await new Promise(resolve => setTimeout(resolve, 2000));
const statusRes = await paymentService.getStatus(orderId, 'wechat');
```

**优点：**
- 不依赖微信回调
- 可以在开发环境使用
- 用户体验较好

**缺点：**
- 查询时机固定（2秒），可能查询时微信回调还没到
- 需要增加手动刷新功能

### 方案2：手动刷新订单状态（已实现）

```typescript
// src/pages/order/payment-success.tsx
const handleRefreshStatus = async () => {
  setRefreshing(true);
  await loadOrder(false);
  Taro.showToast({ 
    title: order?.status === 'processing' ? '订单状态已更新' : '支付确认中，请稍后再试', 
    icon: 'none' 
  });
};
```

在支付成功页面显示"刷新订单状态"按钮，让用户手动刷新。

### 方案3：查看WordPress日志（调试用）

后端已添加详细日志：

```php
// wp-content/plugins/myshop-core/api/payment-controller.php
self::log_debug_always('wechat notify received', [
    'headers' => [...],
    'body_length' => strlen($body),
    'remote_addr' => $_SERVER['REMOTE_ADDR']
]);
```

查看日志位置：
```bash
tail -f e:\laragon\www\agri-ecommerce\wp-content\debug.log
```

### 方案4：体验版/正式版测试

**体验版和正式版的回调应该正常工作**，因为：
- `staging.fanbaoer.com` 和 `fanbaoer.com` 是真实的公网域名
- 服务器稳定在线
- 没有Cloudflare Tunnel的限制

## 测试步骤

### 开发环境测试

1. 创建订单 → 进入支付页面
2. 点击"微信支付"扫码支付
3. 支付成功后，前端自动等待2秒查询支付状态
4. 如果状态仍是"待支付"，点击"刷新订单状态"按钮
5. 等待1-2分钟后再次刷新（给微信回调更多时间）

### 体验版/正式版测试

1. 上传体验版代码
2. 创建订单 → 支付
3. 支付成功后应该自动更新订单状态
4. WordPress后台应该显示"处理中"状态

## 常见问题

### Q1: 为什么支付成功页面显示"已支付"，但订单列表显示"待支付"？

**A:** 之前的代码有bug，支付成功页面写死显示"已支付"：

```typescript
// ❌ 错误写法
const displayStatusText = order.status === 'pending' ? '已支付' : getStatusText(order.status);
```

已修复为根据实际订单状态显示：

```typescript
// ✅ 正确写法
const displayStatusText = getStatusText(order.status);
const isPaid = ['processing', 'completed'].includes(order.status);
```

### Q2: 如何确认微信回调是否到达？

**A:** 查看WordPress日志：

```bash
# Windows (PowerShell)
Get-Content e:\laragon\www\agri-ecommerce\wp-content\debug.log -Tail 50

# 查找关键字
Select-String "wechat notify" e:\laragon\www\agri-ecommerce\wp-content\debug.log
```

如果看到 `wechat notify received`，说明回调到达了。

### Q3: 微信小程序支付功能未开通，能测试吗？

**A:** 可以在开发者工具中测试，但：
- 需要使用测试号或开发者自己的微信
- 支付金额通常很小（0.01元）
- **回调可能无法到达本地环境**
- 建议先在体验版环境测试

### Q4: 如何判断是支付回调的问题还是代码问题？

**A:** 查看以下日志：

1. **前端console**：
   ```
   [Payment] 支付状态查询结果: {success: true, data: {...}}
   ```
   如果 `data.status` 是 "paid"，说明后端已收到支付

2. **WordPress后台**：
   - 订单详情页 → 订单备注
   - 如果有"支付完成"备注，说明payment_complete()被调用了

3. **debug.log**：
   ```
   [wechat notify received] {...}
   ```
   如果有这条日志，说明回调到达了

## 总结

- **开发环境**：支付回调可能失败，依赖前端主动查询 + 手动刷新
- **体验版/正式版**：支付回调应该正常工作
- **关键修复**：移除了支付成功页面写死"已支付"的bug
- **用户体验**：添加了"刷新订单状态"按钮，让用户可以手动更新
