<?php
/**
 * PC端轮播图短代码
 * 可以在 Elementor 的 HTML 小部件中使用，或者直接在主题中调用
 */

add_shortcode('myshop_slider', 'myshop_slider_shortcode');

function myshop_slider_shortcode($atts) {
    // 解析参数
    $atts = shortcode_atts([
        'height' => '400px',
        'autoplay' => 'true',
        'interval' => '3000',
        'show_dots' => 'true',
        'show_arrows' => 'true'
    ], $atts);
    
    // 获取轮播图数据
    $config = get_option('myshop_public_config', []);
    $slides = $config['home_slider'] ?? [];
    
    if (empty($slides)) {
        return '<div class="myshop-slider-empty">暂无轮播图</div>';
    }
    
    // 生成唯一 ID
    $slider_id = 'myshop-slider-' . uniqid();
    
    // 输出 HTML
    ob_start();
    ?>
    
    <div id="<?php echo $slider_id; ?>" class="myshop-slider-container" style="height: <?php echo esc_attr($atts['height']); ?>; position: relative; overflow: hidden;">
        <div class="myshop-slider-wrapper" style="display: flex; transition: transform 0.5s ease;">
            <?php foreach ($slides as $index => $slide): ?>
            <div class="myshop-slide" style="min-width: 100%; height: <?php echo esc_attr($atts['height']); ?>; position: relative;">
                <img src="<?php echo esc_url($slide['img']); ?>" 
                     alt="<?php echo esc_attr($slide['title'] ?? '轮播图'); ?>" 
                     style="width: 100%; height: 100%; object-fit: cover;">
                <?php if (!empty($slide['link'])): ?>
                <a href="<?php echo esc_url($slide['link']); ?>" 
                   style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1;"></a>
                <?php endif; ?>
                
                <?php if (!empty($slide['title'])): ?>
                <div class="myshop-slide-title" style="position: absolute; bottom: 20px; left: 20px; background: rgba(0,0,0,0.6); color: #fff; padding: 10px 20px; border-radius: 4px;">
                    <?php echo esc_html($slide['title']); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($atts['show_dots'] === 'true' && count($slides) > 1): ?>
        <div class="myshop-slider-dots" style="position: absolute; bottom: 15px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 2;">
            <?php foreach ($slides as $index => $slide): ?>
            <span class="myshop-dot <?php echo $index === 0 ? 'active' : ''; ?>" 
                  data-index="<?php echo $index; ?>"
                  style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo $index === 0 ? '#fff' : 'rgba(255,255,255,0.5)'; ?>; cursor: pointer; transition: background 0.3s;"></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($atts['show_arrows'] === 'true' && count($slides) > 1): ?>
        <button class="myshop-prev" style="position: absolute; left: 20px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: #fff; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; z-index: 2; font-size: 20px;">‹</button>
        <button class="myshop-next" style="position: absolute; right: 20px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: #fff; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; z-index: 2; font-size: 20px;">›</button>
        <?php endif; ?>
    </div>
    
    <script>
    (function() {
        var slider = document.getElementById('<?php echo $slider_id; ?>');
        var wrapper = slider.querySelector('.myshop-slider-wrapper');
        var slides = slider.querySelectorAll('.myshop-slide');
        var dots = slider.querySelectorAll('.myshop-dot');
        var prevBtn = slider.querySelector('.myshop-prev');
        var nextBtn = slider.querySelector('.myshop-next');
        
        var currentIndex = 0;
        var totalSlides = slides.length;
        
        function goToSlide(index) {
            currentIndex = index;
            wrapper.style.transform = 'translateX(-' + (index * 100) + '%)';
            
            // 更新指示点
            dots.forEach(function(dot, i) {
                dot.style.background = i === index ? '#fff' : 'rgba(255,255,255,0.5)';
                dot.classList.toggle('active', i === index);
            });
        }
        
        function nextSlide() {
            goToSlide((currentIndex + 1) % totalSlides);
        }
        
        function prevSlide() {
            goToSlide((currentIndex - 1 + totalSlides) % totalSlides);
        }
        
        // 自动播放
        <?php if ($atts['autoplay'] === 'true'): ?>
        var autoplayInterval = setInterval(nextSlide, <?php echo (int)$atts['interval']; ?>);
        
        slider.addEventListener('mouseenter', function() {
            clearInterval(autoplayInterval);
        });
        
        slider.addEventListener('mouseleave', function() {
            autoplayInterval = setInterval(nextSlide, <?php echo (int)$atts['interval']; ?>);
        });
        <?php endif; ?>
        
        // 按钮点击
        if (prevBtn) {
            prevBtn.addEventListener('click', prevSlide);
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', nextSlide);
        }
        
        // 指示点点击
        dots.forEach(function(dot) {
            dot.addEventListener('click', function() {
                goToSlide(parseInt(this.dataset.index));
            });
        });
    })();
    </script>
    
    <?php
    return ob_get_clean();
}

/**
 * Elementor 小部件注册（可选）
 */
add_action('elementor/widgets/register', function($widgets_manager) {
    if (!class_exists('Elementor\Widget_Base')) {
        return;
    }
    
    class MyShop_Slider_Widget extends \Elementor\Widget_Base {
        
        public function get_name() {
            return 'myshop_slider';
        }
        
        public function get_title() {
            return 'MyShop 轮播图';
        }
        
        public function get_icon() {
            return 'eicon-slider-push';
        }
        
        public function get_categories() {
            return ['general'];
        }
        
        protected function register_controls() {
            $this->start_controls_section(
                'settings_section',
                [
                    'label' => '设置',
                    'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
                ]
            );
            
            $this->add_control(
                'height',
                [
                    'label' => '高度',
                    'type' => \Elementor\Controls_Manager::TEXT,
                    'default' => '400px',
                ]
            );
            
            $this->add_control(
                'autoplay',
                [
                    'label' => '自动播放',
                    'type' => \Elementor\Controls_Manager::SWITCHER,
                    'default' => 'yes',
                ]
            );
            
            $this->add_control(
                'interval',
                [
                    'label' => '切换间隔（毫秒）',
                    'type' => \Elementor\Controls_Manager::NUMBER,
                    'default' => 3000,
                ]
            );
            
            $this->end_controls_section();
        }
        
        protected function render() {
            $settings = $this->get_settings_for_display();
            echo do_shortcode('[myshop_slider height="' . esc_attr($settings['height']) . '" autoplay="' . ($settings['autoplay'] === 'yes' ? 'true' : 'false') . '" interval="' . esc_attr($settings['interval']) . '"]');
        }
    }
    
    $widgets_manager->register(new MyShop_Slider_Widget());
}, 100);
