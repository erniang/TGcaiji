<?php
/*
Plugin Name: TG采集发布
Plugin URI: https://www.xxweb.cc/
Description: 从 Telegram 群组采集消息，包括图片和完整文本，保留原格式并允许多选并合并发布到 WordPress。
Version: 1.4
Author: 喜喜资源网
Author URI: https://www.xxweb.cc/
*/

if (!defined('ABSPATH')) exit;

class TelegramMessageCollector {
    private $bot_token = '你的机器人token';                                 //你的机器人token
    private $chat_id = '要采集的群组id';                                   //要采集的群组id

    public function __construct() {
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_action('admin_init', [$this, 'handle_form_submission']);
    }

    public function create_admin_menu() {
        add_menu_page('Telegram Messages', 'TG采集设置', 'manage_options', 'telegram-messages', [$this, 'display_messages_page']);
    }

    private function fetch_messages() {
        $url = "https://api.telegram.org/bot{$this->bot_token}/getUpdates";
        $response = wp_remote_get($url);
        $messages = [];

        if (is_wp_error($response)) return $messages;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['result'])) return $messages;

        foreach ($data['result'] as $result) {
            if (isset($result['message']) && $result['message']['chat']['id'] == $this->chat_id) {
                $text = $result['message']['text'] ?? '';
                $photo = isset($result['message']['photo']) ? end($result['message']['photo']) : null;

                if ($photo) {
                    $file_id = $photo['file_id'];
                    $file_url = $this->get_photo_url($file_id);
                } else {
                    $file_url = '';
                }

                $messages[] = [
                    'text' => $text,
                    'photo_url' => $file_url,
                    'id' => $result['message']['message_id']
                ];
            }
        }

        // 仅保留最新的 10 条消息
        if (count($messages) > 10) {
            $messages = array_slice($messages, -10);
        }

        return $messages;
    }

    private function get_photo_url($file_id) {
        $url = "https://api.telegram.org/bot{$this->bot_token}/getFile?file_id={$file_id}";
        $response = wp_remote_get($url);

        if (is_wp_error($response)) return '';

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['result']['file_path'])) return '';

        $file_path = $data['result']['file_path'];
        return "https://api.telegram.org/file/bot{$this->bot_token}/{$file_path}";
    }

    public function display_messages_page() {
        $messages = $this->fetch_messages();

        echo '<h1>选择消息</h1>';
        echo '<form method="post" action="">';
        wp_nonce_field('telegram_messages_form');

        foreach ($messages as $message) {
            echo '<div>';
            echo '<input type="checkbox" name="selected_messages[]" value="' . esc_attr($message['id']) . '">';
            echo '<p>Text: ' . nl2br(esc_html($message['text'])) . '</p>';

            if ($message['photo_url']) {
                echo '<img src="' . esc_url($message['photo_url']) . '" style="max-width: 300px;"/><br>';
            }

            echo '<input type="hidden" name="message_text_' . esc_attr($message['id']) . '" value="' . esc_textarea($message['text']) . '">';
            echo '<input type="hidden" name="message_photo_' . esc_attr($message['id']) . '" value="' . esc_url($message['photo_url']) . '">';
            echo '</div><hr>';
        }

        echo '<button type="submit" class="button button-primary">选择并发布</button>';
        echo '</form>';
    }

    public function handle_form_submission() {
        if (!current_user_can('manage_options') || !isset($_POST['selected_messages'])) return;
        check_admin_referer('telegram_messages_form');

        $combined_content = '';

        foreach ($_POST['selected_messages'] as $message_id) {
            $message_text = sanitize_textarea_field($_POST['message_text_' . $message_id]);
            $photo_url = esc_url($_POST['message_photo_' . $message_id]);

            // 使用 <p> 标签包裹文本，保持原始换行
            $combined_content .= '<p>' . nl2br($message_text) . '</p>';

            if ($photo_url) {
                $attachment_id = $this->upload_photo_to_media_library($photo_url);
                if ($attachment_id) {
                    // 插入图像到内容中，确保图像位置与采集时一致
                    $photo_html = wp_get_attachment_image($attachment_id, 'full');
                    $combined_content .= '<br>' . $photo_html . '<br>';
                }
            }
            $combined_content .= '<hr>';  // 分隔每条消息
        }

        wp_insert_post([
            'post_title' => '精选影视分享-' . date('Y-m-d'),    //发布时的标题
            'post_content' => $combined_content,
            'post_status' => 'publish',                        //选择公开还是私有
            'post_category' => [1]                             //发布时的分类目录ID
        ]);
    }

    private function upload_photo_to_media_library($photo_url) {
        $tmp = download_url($photo_url);

        if (is_wp_error($tmp)) return 0;

        $file = [
            'name' => basename($photo_url),
            'type' => mime_content_type($tmp),
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp)
        ];

        $id = media_handle_sideload($file, 0);

        if (is_wp_error($id)) {
            @unlink($tmp);
            return 0;
        }

        return $id;
    }
}

new TelegramMessageCollector();
