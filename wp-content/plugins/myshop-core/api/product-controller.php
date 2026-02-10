<?php

class Product_Controller {

    public static function register_routes() {
        register_rest_route('myshop/v1', '/products', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_products'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('myshop/v1', '/products/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_detail'],
            'permission_callback' => '__return_true',
            'args' => ['id' => ['required' => true, 'type' => 'integer']]
        ]);
        
        register_rest_route('myshop/v1', '/products/redeem', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_redeemable_products'],
            'permission_callback' => '__return_true'
        ]);
    }

    public static function list_products() {
        $cache_key = 'myshop_products_list_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return rest_ensure_response(['products' => $cached]);
        }

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

                    $variation_obj = wc_get_product($v['variation_id']);
                    $variations[] = [
                        'variation_id'   => $v['variation_id'],
                        'attributes'     => $attributes,
                        'price'          => $v['display_price'],
                        'image_url'      => !empty($v['image']) ? $v['image']['url'] : null,
                        'in_stock'       => $v['is_in_stock'],
                        'stock_status'   => $variation_obj ? $variation_obj->get_stock_status() : null,
                        'stock_quantity' => $variation_obj ? $variation_obj->get_stock_quantity() : null
                    ];
                }
            }

            // 提取纯文本描述（去除 HTML）
            $description = $p->post_content;
            $description = wp_strip_all_tags($description); // 去除所有 HTML 标签
            $description = preg_replace('/\s+/', ' ', $description); // 多空格/换行合并为单空格
            $description = trim($description);

            // 计算价格区间（仅 variable 类型）
            $min_price = null;
            $max_price = null;
            if (!empty($variations)) {
                $prices = array_column($variations, 'price');
                $min_price = min($prices);
                $max_price = max($prices);
            } else {
                // simple 商品使用单一价格
                $min_price = $max_price = $product->get_price();
            }

            $data[] = [
                'id'          => $p->ID,
                'name'        => $p->post_title,
                'description' => $description, // 纯文本描述
                'price'       => $product->get_price(),
                'min_price'   => $min_price,
                'max_price'   => $max_price,
                'image_url'   => get_the_post_thumbnail_url($p->ID, 'full'),
                'type'        => $product->get_type(),
                'variations'  => $variations
            ];
        }
        set_transient($cache_key, $data, 300);
        return rest_ensure_response(['products' => $data]);
    }

    public static function get_detail($request) {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return new WP_Error('invalid_id', '无效的商品ID', ['status' => 400]);
        }

        $detail_cache_key = 'myshop_product_detail_' . $id;
        $cached = get_transient($detail_cache_key);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
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

            $payload = [
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
            ];
            set_transient($detail_cache_key, $payload, 300);
            return rest_ensure_response($payload);
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

                    $variation_obj = wc_get_product($v['variation_id']);
                    $variations[] = [
                        'id'             => $v['variation_id'],
                        'attributes'     => $attributes,
                        'price'          => $v['display_price'],
                        'image_url'      => !empty($v['image']) ? $v['image']['url'] : null,
                        'in_stock'       => $v['is_in_stock'],
                        'stock_status'   => $variation_obj ? $variation_obj->get_stock_status() : null,
                        'stock_quantity' => $variation_obj ? $variation_obj->get_stock_quantity() : null
                    ];
                }
            }

            $payload = [
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
            ];
            set_transient($detail_cache_key, $payload, 300);
            return rest_ensure_response($payload);
        }

        // 商品不存在或不支持
        return new WP_Error('product_not_found', '商品不存在或不支持', ['status' => 404]);
    }
    
    /**
     * 获取支持积分兑换的商品列表
     * 只返回后台设置为允许积分兑换的商品和变体
     */
    public static function list_redeemable_products() {
        // 获取积分设置
        $points_settings = get_option('myshop_points_settings', []);
        $allowed_product_ids = isset($points_settings['redeem_allowed_product_ids']) 
            ? (array) $points_settings['redeem_allowed_product_ids'] 
            : [];
        $allowed_variation_ids = isset($points_settings['redeem_allowed_variation_ids']) 
            ? (array) $points_settings['redeem_allowed_variation_ids'] 
            : [];
        
        // 自动清理无效的商品和变体ID（在API返回前确保数据是最新的）
        // 这个清理逻辑与后台页面保持一致，确保数据同步
        if (!empty($allowed_product_ids) || !empty($allowed_variation_ids)) {
            // 确保类已加载
            if (!class_exists('MyShop_Points_Manager')) {
                require_once(plugin_dir_path(__FILE__) . '../admin/points-manager.php');
            }
            
            $cleaned = MyShop_Points_Manager::clean_invalid_redeem_products($allowed_product_ids, $allowed_variation_ids);
            
            // 如果有变更，更新设置（自动同步清理后的数据）
            if ($cleaned['has_changes']) {
                $points_settings['redeem_allowed_product_ids'] = $cleaned['product_ids'];
                $points_settings['redeem_allowed_variation_ids'] = $cleaned['variation_ids'];
                update_option('myshop_points_settings', $points_settings);
            }
            
            $allowed_product_ids = $cleaned['product_ids'];
            $allowed_variation_ids = $cleaned['variation_ids'];
        }
        
        // 如果没有设置任何允许的商品，返回空列表
        if (empty($allowed_product_ids) && empty($allowed_variation_ids)) {
            return rest_ensure_response([
                'success' => true,
                'data' => []
            ]);
        }
        
        // 获取所有已发布的商品（只返回实际存在的商品）
        $products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'post__in' => !empty($allowed_product_ids) ? $allowed_product_ids : null,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        $result = [];
        
        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            if (!$product || !in_array($product->get_type(), ['simple', 'variable'])) {
                continue;
            }
            
            $product_id = $product_post->ID;
            $is_variable = $product->is_type('variable');
            
            // 获取变体列表
            $variations = [];
            if ($is_variable) {
                $available_variations = $product->get_available_variations();
                
                foreach ($available_variations as $v) {
                    $variation_id = $v['variation_id'];
                    
                    // 如果设置了允许的变体ID列表，只包含指定的变体（变体ID优先级最高）
                    if (!empty($allowed_variation_ids)) {
                        if (!in_array($variation_id, $allowed_variation_ids)) {
                            continue; // 跳过未允许的变体
                        }
                    } elseif (!empty($allowed_product_ids)) {
                        // 如果只设置了商品ID列表（没有设置变体ID列表），则包含所有变体
                        // 这种情况下，所有变体都可以积分兑换
                    }
                    
                    // 将属性转为中文
                    $attributes = [];
                    foreach ($v['attributes'] as $attr_key => $attr_value) {
                        $taxonomy = str_replace('attribute_', '', $attr_key);
                        $term = get_term_by('slug', $attr_value, $taxonomy);
                        $label = $term ? $term->name : $attr_value;
                        $attributes[$label] = $attr_value;
                    }
                    
                    $variation_obj = wc_get_product($variation_id);
                    $variations[] = [
                        'variation_id'   => $variation_id,
                        'attributes'     => $attributes,
                        'price'          => $v['display_price'],
                        'image_url'      => !empty($v['image']) ? $v['image']['url'] : null,
                        'in_stock'       => $v['is_in_stock'],
                        'stock_status'   => $variation_obj ? $variation_obj->get_stock_status() : null,
                        'stock_quantity' => $variation_obj ? $variation_obj->get_stock_quantity() : null
                    ];
                }
            } else {
                // 简单商品：如果设置了变体ID列表且该商品ID不在列表中，则跳过
                if (!empty($allowed_variation_ids) && !in_array($product_id, $allowed_variation_ids)) {
                    continue;
                }
            }
            
            // 如果设置了变体ID列表，变体商品必须有至少一个允许的变体才显示
            if ($is_variable && !empty($allowed_variation_ids) && empty($variations)) {
                continue;
            }
            
            // 如果同时设置了商品ID和变体ID列表，且商品在商品ID列表中，但该商品的所有变体都不在变体ID列表中，则不显示
            if ($is_variable && !empty($allowed_product_ids) && !empty($allowed_variation_ids)) {
                // 如果商品在允许的商品列表中，但没有任何变体在允许的变体列表中，则不显示
                if (in_array($product_id, $allowed_product_ids) && empty($variations)) {
                    continue;
                }
            }
            
            // 计算价格区间
            $min_price = null;
            $max_price = null;
            if (!empty($variations)) {
                $prices = array_column($variations, 'price');
                $min_price = min($prices);
                $max_price = max($prices);
            } else {
                $min_price = $max_price = $product->get_price();
            }
            
            // 提取纯文本描述
            $description = $product_post->post_content;
            $description = wp_strip_all_tags($description);
            $description = preg_replace('/\s+/', ' ', $description);
            $description = trim($description);
            
            $result[] = [
                'id' => $product_id,
                'name' => $product_post->post_title,
                'description' => $description,
                'price' => $product->get_price(),
                'min_price' => $min_price,
                'max_price' => $max_price,
                'image_url' => get_the_post_thumbnail_url($product_id, 'full'),
                'type' => $product->get_type(),
                'variations' => $variations
            ];
        }
        
        // 如果设置了变体ID列表，还需要处理只选择变体但未选择商品的情况
        if (!empty($allowed_variation_ids) && empty($allowed_product_ids)) {
            foreach ($allowed_variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation || !$variation->is_type('variation')) {
                    continue;
                }
                
                $parent_id = $variation->get_parent_id();
                $parent_product = wc_get_product($parent_id);
                
                // 检查是否已经在结果中
                $already_included = false;
                $existing_item_index = -1;
                foreach ($result as $index => $item) {
                    if ($item['id'] === $parent_id) {
                        $already_included = true;
                        $existing_item_index = $index;
                        break;
                    }
                }
                
                if (!$already_included && $parent_product) {
                    // 只包含这个变体（新商品）
                    $raw_attrs = $variation->get_variation_attributes();
                    $attributes = [];
                    foreach ($raw_attrs as $attr_key => $attr_value) {
                        $taxonomy = str_replace('attribute_', '', $attr_key);
                        $term = get_term_by('slug', $attr_value, $taxonomy);
                        $label = $term ? $term->name : $attr_value;
                        $attributes[$label] = $attr_value;
                    }
                    
                    $variation_name = !empty($attributes) ? implode(' | ', array_keys($attributes)) : '默认规格';
                    
                    $result[] = [
                        'id' => $parent_id,
                        'name' => $parent_product->get_name(),
                        'description' => wp_strip_all_tags($parent_product->get_description()),
                        'price' => $variation->get_price(),
                        'min_price' => $variation->get_price(),
                        'max_price' => $variation->get_price(),
                        'image_url' => get_the_post_thumbnail_url($variation_id, 'full') ?: get_the_post_thumbnail_url($parent_id, 'full'),
                        'type' => 'variable',
                        'variations' => [[
                            'variation_id'   => $variation_id,
                            'attributes'     => $attributes,
                            'price'          => $variation->get_price(),
                            'image_url'      => get_the_post_thumbnail_url($variation_id, 'full') ?: get_the_post_thumbnail_url($parent_id, 'full'),
                            'in_stock'       => $variation->is_in_stock(),
                            'stock_status'   => $variation->get_stock_status(),
                            'stock_quantity' => $variation->get_stock_quantity()
                        ]]
                    ];
                } elseif ($already_included && $existing_item_index >= 0) {
                    // 如果商品已经在结果中，检查这个变体是否已经包含
                    // 如果这个变体不在现有的变体列表中，添加它
                    $existing_item = &$result[$existing_item_index];
                    $variation_exists = false;
                    foreach ($existing_item['variations'] as $existing_var) {
                        if ($existing_var['variation_id'] == $variation_id) {
                            $variation_exists = true;
                            break;
                        }
                    }
                    
                    if (!$variation_exists) {
                        // 添加这个变体到现有商品的变体列表中
                        $raw_attrs = $variation->get_variation_attributes();
                        $attributes = [];
                        foreach ($raw_attrs as $attr_key => $attr_value) {
                            $taxonomy = str_replace('attribute_', '', $attr_key);
                            $term = get_term_by('slug', $attr_value, $taxonomy);
                            $label = $term ? $term->name : $attr_value;
                            $attributes[$label] = $attr_value;
                        }
                        
                        $existing_item['variations'][] = [
                            'variation_id'   => $variation_id,
                            'attributes'     => $attributes,
                            'price'          => $variation->get_price(),
                            'image_url'      => get_the_post_thumbnail_url($variation_id, 'full') ?: get_the_post_thumbnail_url($parent_id, 'full'),
                            'in_stock'       => $variation->is_in_stock(),
                            'stock_status'   => $variation->get_stock_status(),
                            'stock_quantity' => $variation->get_stock_quantity()
                        ];
                        
                        // 重新计算价格区间
                        $prices = array_column($existing_item['variations'], 'price');
                        $existing_item['min_price'] = min($prices);
                        $existing_item['max_price'] = max($prices);
                    }
                }
            }
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => $result
        ]);
    }
}
