<?php

class Product_Controller {

    public static function list_products() {
        $products = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish'
        ]);
        $data = [];
        foreach ($products as $p) {
            $product = wc_get_product($p->ID);
            if (!$product || !in_array($product->get_type(), ['simple', 'variable'])) {
                continue;
            }

            // 获取变体列表（仅 variable 类型）
            $variations = [];
            if ($product->is_type('variable')) {
                $available_variations = $product->get_available_variations();
                foreach ($available_variations as $v) {
                    // 将 attribute_pa_xxx 转为中文属性名
                    $attributes = [];
                    foreach ($v['attributes'] as $attr_key => $attr_value) {
                        $taxonomy = str_replace('attribute_', '', $attr_key);
                        $term = get_term_by('slug', $attr_value, $taxonomy);
                        $label = $term ? $term->name : $attr_value;
                        $attributes[$label] = $attr_value; // 或直接 $attributes[$label] = $label;
                    }

                    $variations[] = [
                        'variation_id' => $v['variation_id'],
                        'attributes'   => $attributes,
                        'price'        => $v['display_price'],
                        'image_url'    => !empty($v['image']) ? $v['image']['url'] : null,
                        'in_stock'     => $v['is_in_stock']
                    ];
                }
            }

            $data[] = [
                'id'          => $p->ID,
                'name'        => $p->post_title, // ← title → name
                'description' => $p->post_content, // 父商品通用描述
                'price'       => $product->get_price(),
                'image_url'   => get_the_post_thumbnail_url($p->ID, 'full'),
                'type'        => $product->get_type(),
                'variations'  => $variations // simple 商品为空数组
            ];
        }
        return rest_ensure_response($data);
    }

    public static function get_detail($request) {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return new WP_Error('invalid_id', '无效的商品ID', ['status' => 400]);
        }

        $product = wc_get_product($id);

        // 如果是变体（variation），直接返回变体详情
        if ($product && $product->is_type('variation')) {
            // 获取属性中文标签
            $raw_attrs = $product->get_variation_attributes();
            $attributes = [];
            foreach ($raw_attrs as $attr_key => $attr_value) {
                $taxonomy = str_replace('attribute_', '', $attr_key);
                $term = get_term_by('slug', $attr_value, $taxonomy);
                $label = $term ? $term->name : $attr_key;
                $attributes[$label] = $attr_value;
            }

            return rest_ensure_response([
                'id'             => $product->get_id(),
                'name'           => $product->get_name(),
                'price'          => $product->get_price(),
                'regular_price'  => $product->get_regular_price(),
                'sale_price'     => $product->get_sale_price(),
                'stock_quantity' => $product->get_stock_quantity(),
                'in_stock'       => $product->is_in_stock(),
                'attributes'     => $attributes,
                'image_url'      => get_the_post_thumbnail_url($product->get_id(), 'full'),
                'type'           => 'variation'
            ]);
        }

        // 如果是简单商品或可变商品父产品
        if ($product && in_array($product->get_type(), ['simple', 'variable'])) {
            $variations = [];
            if ($product->is_type('variable')) {
                $available_variations = $product->get_available_variations();
                foreach ($available_variations as $v) {
                    // 属性转中文
                    $attributes = [];
                    foreach ($v['attributes'] as $attr_key => $attr_value) {
                        $taxonomy = str_replace('attribute_', '', $attr_key);
                        $term = get_term_by('slug', $attr_value, $taxonomy);
                        $label = $term ? $term->name : $attr_value;
                        $attributes[$label] = $attr_value;
                    }

                    $variations[] = [
                        'id'         => $v['variation_id'],
                        'attributes' => $attributes,
                        'price'      => $v['display_price'],
                        'image_url'  => !empty($v['image']) ? $v['image']['url'] : null,
                        'in_stock'   => $v['is_in_stock']
                    ];
                }
            }

            return rest_ensure_response([
                'id'             => $product->get_id(),
                'name'           => $product->get_name(), // ← title → name
                'description'    => $product->get_description(),
                'price'          => $product->get_price(),
                'regular_price'  => $product->get_regular_price(),
                'sale_price'     => $product->get_sale_price(),
                'stock_status'   => $product->get_stock_status(),
                'in_stock'       => $product->is_in_stock(),
                'type'           => $product->get_type(),
                'image_url'      => get_the_post_thumbnail_url($product->get_id(), 'full'),
                'variations'     => $variations
            ]);
        }

        // 商品不存在或不支持
        return new WP_Error('product_not_found', '商品不存在或不支持', ['status' => 404]);
    }
}