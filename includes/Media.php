<?php

declare(strict_types=1);

namespace Camoo\Pay\WooCommerce;

use Camoo\Pay\WooCommerce\Admin\Enum\MediaEnum;
use Camoo\Pay\WooCommerce\Logger\Logger;

use function media_handle_sideload;

final class Media
{
    public function __construct(private ?Logger $logger = null)
    {
        // Ensure that the logger is instantiated correctly
        if ($this->logger === null) {
            $this->logger = new Logger(Plugin::WC_CAMOO_PAY_GATEWAY_ID, WP_DEBUG);
        }
    }

    /** Upload images to the media library if not already uploaded. */
    public function upload_image_to_media_library(): void
    {
        // Define the media items to upload
        $medias = [
            MediaEnum::MOMO_IMAGE->value => 'assets/images/online_momo.png',
            MediaEnum::CAMOO_PAY_ICON->value => 'assets/images/camoo-pay.png',
        ];

        // Loop through each media type and upload if needed
        foreach ($medias as $key => $media) {
            // Get the existing Attachment ID from options
            $installedAttachmentId = get_option($key);

            // Skip if the image is already uploaded
            if ($installedAttachmentId) {
                $this->logger->info(__FILE__, __LINE__, sprintf(
                    "Image '%s' already uploaded with Attachment ID: %d",
                    esc_html($media),
                    esc_html($installedAttachmentId)
                ));
                continue;
            }

            // Upload the image if not already uploaded
            $this->upload_image($key, $media);
        }
    }

    /**
     * Upload a single image to the WordPress Media Library.
     *
     * @param string $key  The option key to store the Attachment ID.
     * @param string $file The relative path to the image file.
     */
    private function upload_image(string $key, string $file): void
    {
        // Full path to the image within the plugin directory
        $image_path = plugin_dir_path(__FILE__) . $file;

        $this->logger->debug(__FILE__, __LINE__, sprintf(
            "Uploading image '%s' from path: %s",
            esc_html($file),
            esc_html($image_path)
        ));

        // Check if the image file exists
        if (!file_exists($image_path)) {
            $this->logger->error(__FILE__, __LINE__, sprintf(
                "Image file '%s' not found.",
                esc_html($file)
            ));

            return;
        }

        // Prepare the file for uploading
        $file_name = basename($image_path);
        $file_type = wp_check_filetype($file_name);
        $file_type = $file_type['type'];

        // Get the WordPress upload directory
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['path'] . '/' . $file_name;

        // Copy the image to the upload directory
        if (!copy($image_path, $upload_path)) {
            $this->logger->error(
                __FILE__,
                __LINE__,
                sprintf(
                    "Failed to copy '%s' to upload directory: %s.",
                    esc_html($file),
                    esc_html($upload_path)
                )
            );

            return;
        }

        // Prepare the media file for WordPress upload
        $file_array = [
            'name' => $file_name,
            'type' => $file_type,
            'tmp_name' => $upload_path,
            'error' => 0,
            'size' => filesize($upload_path),
        ];

        // Upload the file and get the Attachment ID
        $attachment_id = media_handle_sideload($file_array);

        // Check for errors during the upload
        if (is_wp_error($attachment_id)) {
            $this->logger->error(
                __FILE__,
                __LINE__,
                'Error uploading image: ' . esc_html($attachment_id->get_error_message())
            );

            return;
        }

        // Store the Attachment ID in the option table
        update_option($key, $attachment_id);

        // Log success
        $this->logger->info(__FILE__, __LINE__, sprintf(
            "Image '%s' uploaded successfully with Attachment ID: %d",
            esc_html($file),
            esc_html($attachment_id)
        ));
    }
}
