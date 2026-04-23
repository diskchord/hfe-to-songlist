<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class HFE_To_Songlist_Plugin
{
    private const SHORTCODE = 'hfe_songlist';
    private const MAX_UPLOAD_BYTES = 16777216; // 16 MiB.
    private const ESEQ_TITLE_OFFSET = 0x57;
    private const ESEQ_TITLE_LENGTH = 0x20;

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
        add_action('wp_ajax_hfe_songlist_generate', [$this, 'ajax_generate_songlist']);
        add_action('wp_ajax_nopriv_hfe_songlist_generate', [$this, 'ajax_generate_songlist']);
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function render_shortcode(array $atts = []): string
    {
        unset($atts);

        $this->enqueue_assets();

        $result = null;
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && isset($_POST['hfe_songlist_action'])
            && sanitize_key((string) wp_unslash($_POST['hfe_songlist_action'])) === 'upload'
        ) {
            $nonce = isset($_POST['hfe_songlist_nonce']) ? (string) wp_unslash($_POST['hfe_songlist_nonce']) : '';
            if (!wp_verify_nonce($nonce, 'hfe_songlist_upload')) {
                $result = [
                    'success' => false,
                    'error' => __('Security check failed. Please try again.', 'hfe-to-songlist'),
                ];
            } else {
                $result = $this->handle_upload_request();
            }
        }

        /** @var array<int, array{filename:string,title:string}> $songs */
        $songs = [];
        $songlist_text = '';
        $album_name = __('Unknown Album', 'hfe-to-songlist');
        $disk_kb = 0;
        $message_text = '';
        $message_class = '';
        $ai_cleanup_checked = $this->is_ai_cleanup_requested();

        if (is_array($result) && !empty($result['success'])) {
            $songs = is_array($result['songs'] ?? null) ? $result['songs'] : [];
            $songlist_text = is_string($result['songlist_text'] ?? null) ? $result['songlist_text'] : '';
            $album_name = is_string($result['album_name'] ?? null) ? $result['album_name'] : $album_name;
            $disk_kb = is_int($result['disk_kb'] ?? null) ? $result['disk_kb'] : 0;
            $message_text = __('Songlist generated successfully.', 'hfe-to-songlist');
            if (!empty($result['notice']) && is_string($result['notice'])) {
                $message_text .= ' ' . $result['notice'];
            }
            $message_class = 'hfe-songlist-success';
        } elseif (is_array($result) && empty($result['success'])) {
            $message_text = (string) ($result['error'] ?? __('Unable to process file.', 'hfe-to-songlist'));
            $message_class = 'hfe-songlist-error';
        }

        $input_id = wp_unique_id('hfe-songlist-file-');
        $show_result = $songs !== [];

        ob_start();
        ?>
        <div class="hfe-songlist-wrap" data-hfe-songlist="1">
            <div class="hfe-songlist-shell">
                <div class="hfe-songlist-header">
                    <h3><?php esc_html_e('HFE to Songlist', 'hfe-to-songlist'); ?></h3>
                    <p><?php esc_html_e('Upload a Yamaha HFE disk image and generate a copy-ready album songlist.', 'hfe-to-songlist'); ?></p>
                </div>

                <form method="post" enctype="multipart/form-data" class="hfe-songlist-form">
                    <?php wp_nonce_field('hfe_songlist_upload', 'hfe_songlist_nonce'); ?>
                    <input type="hidden" name="hfe_songlist_action" value="upload">

                    <label for="<?php echo esc_attr($input_id); ?>" class="hfe-songlist-label"><?php esc_html_e('HFE File', 'hfe-to-songlist'); ?></label>
                    <input
                        id="<?php echo esc_attr($input_id); ?>"
                        type="file"
                        class="hfe-songlist-file-input"
                        name="hfe_songlist_file"
                        accept=".hfe,.HFE"
                        required
                    >
                    <p class="hfe-songlist-help"><?php esc_html_e('Supports 720 KB and 1440 KB FAT floppy disk images.', 'hfe-to-songlist'); ?></p>

                    <label class="hfe-songlist-option">
                        <input
                            type="checkbox"
                            name="hfe_songlist_ai_cleanup"
                            class="hfe-songlist-ai-cleanup"
                            value="1"
                            <?php checked($ai_cleanup_checked); ?>
                        >
                        <span>
                            <strong><?php esc_html_e('AI title cleanup', 'hfe-to-songlist'); ?></strong>
                            <?php esc_html_e('(optional)', 'hfe-to-songlist'); ?>
                        </span>
                    </label>

                    <div class="hfe-songlist-actions">
                        <button type="submit" class="button button-primary hfe-songlist-submit">
                            <?php esc_html_e('Generate Songlist', 'hfe-to-songlist'); ?>
                        </button>
                        <span class="hfe-songlist-status" aria-live="polite"></span>
                    </div>
                </form>

                <div class="hfe-songlist-message <?php echo esc_attr($message_class); ?><?php echo $message_text === '' ? ' hfe-songlist-hidden' : ''; ?>">
                    <?php echo esc_html($message_text); ?>
                </div>

                <div class="hfe-songlist-result<?php echo $show_result ? '' : ' hfe-songlist-hidden'; ?>">
                    <div class="hfe-songlist-meta">
                        <p><strong><?php esc_html_e('Album:', 'hfe-to-songlist'); ?></strong> <span data-hfe-field="album"><?php echo esc_html($album_name); ?></span></p>
                        <p class="hfe-songlist-disk<?php echo $disk_kb > 0 ? '' : ' hfe-songlist-hidden'; ?>">
                            <strong><?php esc_html_e('Disk Type:', 'hfe-to-songlist'); ?></strong>
                            <span data-hfe-field="disk"><?php echo esc_html($disk_kb > 0 ? ((string) $disk_kb . ' KB') : ''); ?></span>
                        </p>
                    </div>

                    <label for="<?php echo esc_attr($input_id . '-text'); ?>" class="hfe-songlist-label"><?php esc_html_e('Copy-Friendly Output', 'hfe-to-songlist'); ?></label>
                    <textarea id="<?php echo esc_attr($input_id . '-text'); ?>" class="hfe-songlist-textarea" readonly rows="12"><?php echo esc_textarea($songlist_text); ?></textarea>

                    <table class="hfe-songlist-table" aria-live="polite">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('#', 'hfe-to-songlist'); ?></th>
                            <th><?php esc_html_e('Song Title', 'hfe-to-songlist'); ?></th>
                            <th><?php esc_html_e('Source File', 'hfe-to-songlist'); ?></th>
                        </tr>
                        </thead>
                        <tbody class="hfe-songlist-table-body">
                        <?php if ($show_result) : ?>
                            <?php foreach ($songs as $index => $song) : ?>
                                <tr>
                                    <td><?php echo esc_html(sprintf('%02d', (int) $index + 1)); ?></td>
                                    <td><?php echo esc_html($song['title']); ?></td>
                                    <td><code><?php echo esc_html($song['filename']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function ajax_generate_songlist(): void
    {
        $nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'hfe_songlist_upload')) {
            wp_send_json_error(
                ['error' => __('Security check failed. Please refresh and try again.', 'hfe-to-songlist')],
                403
            );
        }

        $result = $this->handle_upload_request();
        if (!empty($result['success'])) {
            wp_send_json_success($this->success_payload($result));
        }

        wp_send_json_error(
            ['error' => (string) ($result['error'] ?? __('Unable to process file.', 'hfe-to-songlist'))],
            400
        );
    }

    private function enqueue_assets(): void
    {
        wp_enqueue_style(
            'hfe-songlist',
            plugins_url('assets/css/hfe-songlist.css', HFE_TO_SONGLIST_PLUGIN_FILE),
            [],
            HFE_TO_SONGLIST_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'hfe-songlist',
            plugins_url('assets/js/hfe-songlist.js', HFE_TO_SONGLIST_PLUGIN_FILE),
            [],
            HFE_TO_SONGLIST_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            'hfe-songlist',
            'hfeSonglistConfig',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action' => 'hfe_songlist_generate',
                'nonce' => wp_create_nonce('hfe_songlist_upload'),
                'i18n' => [
                    'processing' => __('Processing disk image. This can take a moment.', 'hfe-to-songlist'),
                    'processingAi' => __('Processing disk image and running AI cleanup. This can take a bit longer.', 'hfe-to-songlist'),
                    'noFile' => __('Choose an HFE file first.', 'hfe-to-songlist'),
                    'success' => __('Songlist generated successfully.', 'hfe-to-songlist'),
                    'networkError' => __('Network error while uploading or processing.', 'hfe-to-songlist'),
                    'genericError' => __('Unable to process file.', 'hfe-to-songlist'),
                ],
            ]
        );
    }

    /**
     * @param array{success:bool,error?:string,notice?:string,album_name?:string,disk_kb?:int,songs?:array<int,array{filename:string,title:string}>,songlist_text?:string} $result
     * @return array{album_name:string,disk_kb:int,songlist_text:string,notice:string,songs:array<int,array{filename:string,title:string}>}
     */
    private function success_payload(array $result): array
    {
        $songs = [];
        if (is_array($result['songs'] ?? null)) {
            foreach ($result['songs'] as $song) {
                if (!is_array($song)) {
                    continue;
                }

                $songs[] = [
                    'filename' => (string) ($song['filename'] ?? ''),
                    'title' => (string) ($song['title'] ?? ''),
                ];
            }
        }

        return [
            'album_name' => (string) ($result['album_name'] ?? __('Unknown Album', 'hfe-to-songlist')),
            'disk_kb' => (int) ($result['disk_kb'] ?? 0),
            'songlist_text' => (string) ($result['songlist_text'] ?? ''),
            'notice' => (string) ($result['notice'] ?? ''),
            'songs' => $songs,
        ];
    }

    /**
     * @return array{success:bool,error?:string,notice?:string,album_name?:string,disk_kb?:int,songs?:array<int,array{filename:string,title:string}>,songlist_text?:string}
     */
    private function handle_upload_request(): array
    {
        if (!isset($_FILES['hfe_songlist_file']) || !is_array($_FILES['hfe_songlist_file'])) {
            return $this->error_result(__('No file was uploaded.', 'hfe-to-songlist'));
        }

        /** @var array{name?:string,tmp_name?:string,error?:int,size?:int} $file */
        $file = $_FILES['hfe_songlist_file'];

        $upload_error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upload_error !== UPLOAD_ERR_OK) {
            return $this->error_result($this->upload_error_message($upload_error));
        }

        $tmp_name = (string) ($file['tmp_name'] ?? '');
        $original_name = (string) ($file['name'] ?? 'album.hfe');
        $size = (int) ($file['size'] ?? 0);

        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            return $this->error_result(__('Upload failed validation. Please try again.', 'hfe-to-songlist'));
        }

        if ($size <= 0) {
            return $this->error_result(__('Uploaded file is empty.', 'hfe-to-songlist'));
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            return $this->error_result(__('Uploaded file is too large.', 'hfe-to-songlist'));
        }

        $extension = strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION));
        if ($extension !== 'hfe') {
            return $this->error_result(__('Please upload a file with the .hfe extension.', 'hfe-to-songlist'));
        }

        $work_dir = $this->create_work_dir();
        if ($work_dir === '') {
            return $this->error_result(__('Unable to create a temporary workspace.', 'hfe-to-songlist'));
        }

        $uploaded_hfe_path = $work_dir . DIRECTORY_SEPARATOR . 'upload.hfe';
        if (!move_uploaded_file($tmp_name, $uploaded_hfe_path)) {
            $this->delete_directory($work_dir);

            return $this->error_result(__('Unable to move the uploaded file.', 'hfe-to-songlist'));
        }

        $use_ai_cleanup = $this->is_ai_cleanup_requested();

        try {
            return $this->process_hfe_file($uploaded_hfe_path, $original_name, $work_dir, $use_ai_cleanup);
        } finally {
            $this->delete_directory($work_dir);
        }
    }

    /**
     * @return array{success:bool,error?:string,notice?:string,album_name?:string,disk_kb?:int,songs?:array<int,array{filename:string,title:string}>,songlist_text?:string}
     */
    private function process_hfe_file(string $hfe_path, string $original_name, string $work_dir, bool $use_ai_cleanup = false): array
    {
        $gw_binary = $this->gw_binary();
        $seven_z_binary = $this->seven_z_binary();

        if (!$this->is_command_available($gw_binary)) {
            return $this->error_result(__('Greaseweazle command `gw` is not available on this server.', 'hfe-to-songlist'));
        }

        if (!$this->is_command_available($seven_z_binary)) {
            return $this->error_result(__('7-Zip command `7z` is not available on this server.', 'hfe-to-songlist'));
        }

        $header = $this->read_hfe_header($hfe_path);
        if (!$header['valid']) {
            return $this->error_result($header['error'] ?? __('Invalid HFE file.', 'hfe-to-songlist'));
        }

        if (
            $header['tracks'] !== 80
            || $header['sides'] !== 2
            || !in_array($header['bitrate'], [250, 500], true)
        ) {
            return $this->error_result(
                __('Only 80-track, 2-sided 720 KB (250 kbps) or 1440 KB (500 kbps) HFE images are supported.', 'hfe-to-songlist')
            );
        }

        $convert_result = $this->convert_hfe_to_img($hfe_path, $work_dir, $header['preferred_format']);
        if (!$convert_result['success']) {
            if (!empty($convert_result['debug'])) {
                error_log('[HFE Songlist] Convert failed: ' . (string) $convert_result['debug']);
            }

            return $this->error_result(
                __('Could not convert this HFE into a valid 720/1440 KB FAT image.', 'hfe-to-songlist')
            );
        }

        $extract_dir = $work_dir . DIRECTORY_SEPARATOR . 'extract';
        if (!wp_mkdir_p($extract_dir)) {
            return $this->error_result(__('Unable to create extraction directory.', 'hfe-to-songlist'));
        }

        $extract_result = $this->run_command([
            $seven_z_binary,
            'x',
            '-y',
            '-o' . $extract_dir,
            $convert_result['img_path'],
        ]);

        if ($extract_result['exit_code'] !== 0) {
            return $this->error_result(__('Unable to extract files from the converted image.', 'hfe-to-songlist'));
        }

        $songs = $this->collect_songs($extract_dir);
        if ($songs === []) {
            return $this->error_result(__('No MIDI or E-SEQ/FIL files were found in this disk image.', 'hfe-to-songlist'));
        }

        $album_name = $this->album_name_from_filename($original_name);
        $notice = '';
        if ($use_ai_cleanup) {
            $cleanup_result = $this->cleanup_titles_with_ai($album_name, $songs);
            if (!empty($cleanup_result['success']) && is_array($cleanup_result['songs'] ?? null)) {
                $songs = $cleanup_result['songs'];
                $notice = __('AI cleanup applied.', 'hfe-to-songlist');
            } else {
                $notice = (string) ($cleanup_result['notice'] ?? __('AI cleanup could not be applied; using original parsed titles.', 'hfe-to-songlist'));
                if (!empty($cleanup_result['debug'])) {
                    error_log('[HFE Songlist] AI cleanup failed: ' . (string) $cleanup_result['debug']);
                }
            }
        }

        $songlist_text = $this->format_songlist_text($album_name, $songs);

        return [
            'success' => true,
            'album_name' => $album_name,
            'disk_kb' => $convert_result['disk_kb'],
            'songs' => $songs,
            'songlist_text' => $songlist_text,
            'notice' => $notice,
        ];
    }

    /**
     * @return array{valid:bool,error?:string,tracks?:int,sides?:int,bitrate?:int,preferred_format?:string}
     */
    private function read_hfe_header(string $hfe_path): array
    {
        $header = @file_get_contents($hfe_path, false, null, 0, 32);
        if (!is_string($header) || strlen($header) < 20) {
            return [
                'valid' => false,
                'error' => __('File is too short to be a valid HFE image.', 'hfe-to-songlist'),
            ];
        }

        if (substr($header, 0, 8) !== 'HXCPICFE') {
            return [
                'valid' => false,
                'error' => __('File does not contain a valid HFE signature.', 'hfe-to-songlist'),
            ];
        }

        $tracks = ord($header[9]);
        $sides = ord($header[10]);
        $bitrate = unpack('vbitrate', substr($header, 12, 2));
        $bitrate_value = is_array($bitrate) && isset($bitrate['bitrate']) ? (int) $bitrate['bitrate'] : 0;
        $preferred_format = $bitrate_value >= 400 ? 'ibm.1440' : 'ibm.720';

        return [
            'valid' => true,
            'tracks' => $tracks,
            'sides' => $sides,
            'bitrate' => $bitrate_value,
            'preferred_format' => $preferred_format,
        ];
    }

    /**
     * @return array{success:bool,img_path?:string,disk_kb?:int,debug?:string}
     */
    private function convert_hfe_to_img(string $hfe_path, string $work_dir, string $preferred_format): array
    {
        $formats = [$preferred_format];
        if ($preferred_format === 'ibm.720') {
            $formats[] = 'ibm.1440';
        } else {
            $formats[] = 'ibm.720';
        }

        $gw_binary = $this->gw_binary();
        $attempts = [];

        foreach ($formats as $format) {
            $img_path = $work_dir . DIRECTORY_SEPARATOR . str_replace('.', '-', $format) . '.img';
            $convert_result = $this->run_command([
                $gw_binary,
                'convert',
                '--format',
                $format,
                $hfe_path,
                $img_path,
            ]);

            if ($convert_result['exit_code'] !== 0 || !is_file($img_path)) {
                $attempts[] = sprintf(
                    '%s: gw exit=%d img=%s out=%s',
                    $format,
                    (int) $convert_result['exit_code'],
                    is_file($img_path) ? 'yes' : 'no',
                    $this->clip_debug_output($convert_result['output'])
                );
                continue;
            }

            $expected_size = $this->expected_img_size_for_format($format);
            if ($expected_size <= 0 || filesize($img_path) !== $expected_size) {
                $actual_size = is_file($img_path) ? (int) filesize($img_path) : 0;
                $attempts[] = sprintf(
                    '%s: wrong size expected=%d actual=%d',
                    $format,
                    $expected_size,
                    $actual_size
                );
                continue;
            }

            $list_result = $this->run_command([$this->seven_z_binary(), 'l', $img_path]);
            if (!$this->is_valid_fat_listing($list_result)) {
                $attempts[] = sprintf(
                    '%s: 7z listing not FAT exit=%d out=%s',
                    $format,
                    (int) $list_result['exit_code'],
                    $this->clip_debug_output($list_result['output'])
                );
                continue;
            }

            return [
                'success' => true,
                'img_path' => $img_path,
                'disk_kb' => $format === 'ibm.1440' ? 1440 : 720,
            ];
        }

        return [
            'success' => false,
            'debug' => implode(' || ', $attempts),
        ];
    }

    private function clip_debug_output(string $output): string
    {
        $output = preg_replace('/\s+/', ' ', trim($output)) ?? '';
        if ($output === '') {
            return '(empty)';
        }

        if (strlen($output) > 280) {
            return substr($output, 0, 280) . '...';
        }

        return $output;
    }

    /**
     * @param array{exit_code:int,output:string} $list_result
     */
    private function is_valid_fat_listing(array $list_result): bool
    {
        $output = $list_result['output'];
        if (stripos($output, 'Cannot open the file as archive') !== false) {
            return false;
        }

        return stripos($output, 'Type = FAT') !== false;
    }

    /**
     * @return array<int, array{filename:string,title:string}>
     */
    private function collect_songs(string $extract_dir): array
    {
        $songs = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extract_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
            if (!$file_info instanceof SplFileInfo || !$file_info->isFile()) {
                continue;
            }

            $filename = $file_info->getBasename();
            $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($extension, ['mid', 'midi', 'fil', 'eseq'], true)) {
                continue;
            }

            if (strtoupper($filename) === 'PIANODIR.FIL') {
                continue;
            }

            $path = $file_info->getPathname();
            $title = '';
            if ($extension === 'mid' || $extension === 'midi') {
                $title = $this->extract_midi_title($path);
            } else {
                $title = $this->extract_eseq_title($path);
            }

            if ($title === '') {
                $title = $this->humanize_filename($filename);
            }

            $songs[] = [
                'filename' => $filename,
                'title' => $title,
                'sort_index' => $this->filename_prefix_number($filename),
            ];
        }

        usort(
            $songs,
            static function (array $left, array $right): int {
                $left_index = $left['sort_index'];
                $right_index = $right['sort_index'];

                if (is_int($left_index) && is_int($right_index) && $left_index !== $right_index) {
                    return $left_index <=> $right_index;
                }

                if (is_int($left_index) && !is_int($right_index)) {
                    return -1;
                }

                if (!is_int($left_index) && is_int($right_index)) {
                    return 1;
                }

                return strnatcasecmp((string) $left['filename'], (string) $right['filename']);
            }
        );

        $normalized = [];
        foreach ($songs as $song) {
            $normalized[] = [
                'filename' => (string) $song['filename'],
                'title' => (string) $song['title'],
            ];
        }

        return $normalized;
    }

    private function extract_eseq_title(string $path): string
    {
        $data = @file_get_contents($path, false, null, 0, 256);
        if (!is_string($data)) {
            return '';
        }

        if (strlen($data) < (self::ESEQ_TITLE_OFFSET + self::ESEQ_TITLE_LENGTH)) {
            return '';
        }

        $raw_title = substr($data, self::ESEQ_TITLE_OFFSET, self::ESEQ_TITLE_LENGTH);
        if (!is_string($raw_title)) {
            return '';
        }

        return $this->normalize_embedded_text($raw_title);
    }

    private function extract_midi_title(string $path): string
    {
        $data = @file_get_contents($path, false, null, 0, 524288);
        if (!is_string($data) || strlen($data) < 14 || substr($data, 0, 4) !== 'MThd') {
            return '';
        }

        $header_length = $this->read_uint32_be($data, 4);
        if ($header_length < 6) {
            return '';
        }

        $limit = strlen($data);
        $cursor = 8 + $header_length;
        while ($cursor + 8 <= $limit) {
            if (substr($data, $cursor, 4) !== 'MTrk') {
                break;
            }

            $track_length = $this->read_uint32_be($data, $cursor + 4);
            $track_start = $cursor + 8;
            $track_end = min($track_start + $track_length, $limit);

            $title = $this->extract_midi_title_from_track($data, $track_start, $track_end);
            if ($title !== '') {
                return $title;
            }

            $cursor = $track_start + $track_length;
        }

        return '';
    }

    private function extract_midi_title_from_track(string $data, int $track_start, int $track_end): string
    {
        $cursor = $track_start;
        $running_status = null;

        while ($cursor < $track_end) {
            [$delta, $delta_len] = $this->read_var_length_int($data, $cursor, $track_end);
            unset($delta);
            if ($delta_len === 0) {
                break;
            }
            $cursor += $delta_len;

            if ($cursor >= $track_end) {
                break;
            }

            $status = ord($data[$cursor]);
            if ($status < 0x80) {
                if (!is_int($running_status)) {
                    break;
                }
                $status = $running_status;
            } else {
                $cursor++;
                if ($status < 0xF0) {
                    $running_status = $status;
                } else {
                    $running_status = null;
                }
            }

            if ($status === 0xFF) {
                if ($cursor >= $track_end) {
                    break;
                }

                $meta_type = ord($data[$cursor]);
                $cursor++;
                [$meta_len, $meta_len_bytes] = $this->read_var_length_int($data, $cursor, $track_end);
                if ($meta_len_bytes === 0) {
                    break;
                }
                $cursor += $meta_len_bytes;
                if ($cursor + $meta_len > $track_end) {
                    break;
                }

                if ($meta_type === 0x03 || $meta_type === 0x01) {
                    $text = substr($data, $cursor, $meta_len);
                    if (is_string($text)) {
                        $title = $this->normalize_embedded_text($text);
                        if ($title !== '') {
                            return $title;
                        }
                    }
                }

                $cursor += $meta_len;
                continue;
            }

            if ($status === 0xF0 || $status === 0xF7) {
                [$sysex_len, $sysex_len_bytes] = $this->read_var_length_int($data, $cursor, $track_end);
                if ($sysex_len_bytes === 0) {
                    break;
                }
                $cursor += $sysex_len_bytes + $sysex_len;
                continue;
            }

            $event_type = $status & 0xF0;
            $data_bytes = in_array($event_type, [0xC0, 0xD0], true) ? 1 : 2;
            if ($cursor + $data_bytes > $track_end) {
                break;
            }
            $cursor += $data_bytes;
        }

        return '';
    }

    /**
     * @return array{0:int,1:int}
     */
    private function read_var_length_int(string $data, int $offset, int $limit): array
    {
        $value = 0;
        $length = 0;

        while ($offset + $length < $limit && $length < 4) {
            $byte = ord($data[$offset + $length]);
            $value = ($value << 7) | ($byte & 0x7F);
            $length++;
            if (($byte & 0x80) === 0) {
                return [$value, $length];
            }
        }

        return [0, 0];
    }

    private function read_uint32_be(string $data, int $offset): int
    {
        $bytes = substr($data, $offset, 4);
        if (!is_string($bytes) || strlen($bytes) !== 4) {
            return 0;
        }

        $unpacked = unpack('Nvalue', $bytes);
        if (!is_array($unpacked) || !isset($unpacked['value'])) {
            return 0;
        }

        return (int) $unpacked['value'];
    }

    private function normalize_embedded_text(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]+/', ' ', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim($text);
    }

    private function humanize_filename(string $filename): string
    {
        $name = (string) pathinfo($filename, PATHINFO_FILENAME);
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = trim($name);

        return $name !== '' ? $name : $filename;
    }

    private function filename_prefix_number(string $filename): ?int
    {
        $name = (string) pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/^(\d{1,3})/', $name, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param array<int, array{filename:string,title:string}> $songs
     */
    private function format_songlist_text(string $album_name, array $songs): string
    {
        $lines = [$album_name];
        foreach ($songs as $index => $song) {
            $lines[] = sprintf('  %02d. %s', (int) $index + 1, $song['title']);
        }

        return implode("\n", $lines);
    }

    private function album_name_from_filename(string $original_name): string
    {
        $album_name = (string) pathinfo($original_name, PATHINFO_FILENAME);
        $album_name = str_replace(['_', '-'], ' ', $album_name);
        $album_name = preg_replace('/\s+/', ' ', $album_name) ?? $album_name;
        $album_name = trim($album_name);

        return $album_name !== '' ? $album_name : __('Unknown Album', 'hfe-to-songlist');
    }

    private function is_ai_cleanup_requested(): bool
    {
        $value = isset($_POST['hfe_songlist_ai_cleanup']) ? (string) wp_unslash($_POST['hfe_songlist_ai_cleanup']) : '';
        $value = strtolower(trim($value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int, array{filename:string,title:string}> $songs
     * @return array{success:bool,songs?:array<int,array{filename:string,title:string}>,notice?:string,debug?:string}
     */
    private function cleanup_titles_with_ai(string $album_name, array $songs): array
    {
        $api_key = $this->openai_api_key();
        if ($api_key === '') {
            return [
                'success' => false,
                'notice' => __('AI cleanup requested, but OpenAI API key is not configured.', 'hfe-to-songlist'),
            ];
        }

        $input_payload = [
            'album_name' => $album_name,
            'titles' => array_map(
                static function (array $song): string {
                    return (string) ($song['title'] ?? '');
                },
                $songs
            ),
            'source_filenames' => array_map(
                static function (array $song): string {
                    return (string) ($song['filename'] ?? '');
                },
                $songs
            ),
        ];

        $request_body = [
            'model' => $this->openai_model(),
            'store' => false,
            'instructions' => 'Clean up extracted song titles for a disk songlist. Keep each title faithful, readable, and concise. Preserve language and intent, fix spacing/capitalization/minor OCR artifacts, and do not invent new titles. Return titles in the same order and count.',
            'input' => wp_json_encode($input_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'song_title_cleanup',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'titles' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'maxLength' => 160,
                                ],
                            ],
                        ],
                        'required' => ['titles'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $response = wp_remote_post(
            $this->openai_api_url(),
            [
                'timeout' => 45,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($request_body),
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'notice' => __('AI cleanup failed; using original parsed titles.', 'hfe-to-songlist'),
                'debug' => 'request_error: ' . $response->get_error_message(),
            ];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status_code < 200 || $status_code >= 300) {
            return [
                'success' => false,
                'notice' => __('AI cleanup failed; using original parsed titles.', 'hfe-to-songlist'),
                'debug' => sprintf('http_%d body=%s', $status_code, $this->clip_debug_output($body)),
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'notice' => __('AI cleanup failed; using original parsed titles.', 'hfe-to-songlist'),
                'debug' => 'invalid_json_response: ' . $this->clip_debug_output($body),
            ];
        }

        $candidate_titles = $this->extract_ai_titles_from_response($decoded);
        if ($candidate_titles === null || $candidate_titles === []) {
            return [
                'success' => false,
                'notice' => __('AI cleanup failed; using original parsed titles.', 'hfe-to-songlist'),
                'debug' => 'no_titles_in_response',
            ];
        }

        $cleaned_songs = [];
        foreach ($songs as $index => $song) {
            $new_title = isset($candidate_titles[$index]) ? $this->normalize_ai_text((string) $candidate_titles[$index]) : '';
            if ($new_title === '') {
                $new_title = (string) $song['title'];
            }

            $cleaned_songs[] = [
                'filename' => (string) $song['filename'],
                'title' => $new_title,
            ];
        }

        return [
            'success' => true,
            'songs' => $cleaned_songs,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, string>|null
     */
    private function extract_ai_titles_from_response(array $response): ?array
    {
        $titles = $this->parse_ai_titles_payload($response['output_text'] ?? null);
        if (is_array($titles)) {
            return $titles;
        }

        if (isset($response['output']) && is_array($response['output'])) {
            foreach ($response['output'] as $item) {
                if (!is_array($item) || !isset($item['content']) || !is_array($item['content'])) {
                    continue;
                }

                foreach ($item['content'] as $content) {
                    if (!is_array($content)) {
                        continue;
                    }

                    if (($content['type'] ?? '') === 'output_json') {
                        $titles = $this->parse_ai_titles_payload($content['json'] ?? null);
                        if (is_array($titles)) {
                            return $titles;
                        }
                    }

                    if (($content['type'] ?? '') === 'output_text') {
                        $titles = $this->parse_ai_titles_payload($content['text'] ?? null);
                        if (is_array($titles)) {
                            return $titles;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>|null
     */
    private function parse_ai_titles_payload(mixed $payload): ?array
    {
        if (is_string($payload)) {
            $payload = trim($payload);
            if ($payload === '') {
                return null;
            }

            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload) || !isset($payload['titles']) || !is_array($payload['titles'])) {
            return null;
        }

        $titles = [];
        foreach ($payload['titles'] as $title) {
            if (!is_string($title)) {
                continue;
            }

            $titles[] = $this->normalize_ai_text($title);
        }

        return $titles === [] ? null : $titles;
    }

    private function normalize_ai_text(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function openai_api_key(): string
    {
        $api_key = '';
        if (defined('HFE_SONGLIST_OPENAI_API_KEY')) {
            $api_key = (string) HFE_SONGLIST_OPENAI_API_KEY;
        }

        if ($api_key === '') {
            $api_key = (string) getenv('OPENAI_API_KEY');
        }

        return (string) apply_filters('hfe_songlist_openai_api_key', trim($api_key));
    }

    private function openai_model(): string
    {
        $model = defined('HFE_SONGLIST_OPENAI_MODEL') ? (string) HFE_SONGLIST_OPENAI_MODEL : 'gpt-5.4-nano';

        return (string) apply_filters('hfe_songlist_openai_model', $model);
    }

    private function openai_api_url(): string
    {
        $url = defined('HFE_SONGLIST_OPENAI_API_URL') ? (string) HFE_SONGLIST_OPENAI_API_URL : 'https://api.openai.com/v1/responses';

        return (string) apply_filters('hfe_songlist_openai_api_url', $url);
    }

    private function expected_img_size_for_format(string $format): int
    {
        if ($format === 'ibm.720') {
            return 737280;
        }

        if ($format === 'ibm.1440') {
            return 1474560;
        }

        return 0;
    }

    private function gw_binary(): string
    {
        $binary = defined('HFE_SONGLIST_GW_BINARY') ? (string) HFE_SONGLIST_GW_BINARY : 'gw';

        return (string) apply_filters('hfe_songlist_gw_binary', $binary);
    }

    private function seven_z_binary(): string
    {
        $binary = defined('HFE_SONGLIST_7Z_BINARY') ? (string) HFE_SONGLIST_7Z_BINARY : '7z';

        return (string) apply_filters('hfe_songlist_7z_binary', $binary);
    }

    private function is_command_available(string $command): bool
    {
        if ($command === '') {
            return false;
        }

        if (str_contains($command, DIRECTORY_SEPARATOR)) {
            return is_executable($command);
        }

        $result = $this->run_command(['which', $command]);

        return $result['exit_code'] === 0 && trim($result['output']) !== '';
    }

    /**
     * @param array<int, string> $parts
     * @return array{exit_code:int,output:string}
     */
    private function run_command(array $parts): array
    {
        $command = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
        $output = [];
        $exit_code = 1;
        @exec($command, $output, $exit_code);

        return [
            'exit_code' => (int) $exit_code,
            'output' => implode("\n", $output),
        ];
    }

    private function create_work_dir(): string
    {
        $upload = wp_get_upload_dir();
        $base_dir = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        if ($base_dir === '') {
            return '';
        }

        $temp_root = trailingslashit($base_dir) . 'hfe-songlist-temp';
        if (!wp_mkdir_p($temp_root)) {
            return '';
        }

        $work_dir = $temp_root . DIRECTORY_SEPARATOR . wp_generate_uuid4();
        if (!wp_mkdir_p($work_dir)) {
            return '';
        }

        return $work_dir;
    }

    private function delete_directory(string $path): void
    {
        if ($path === '') {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function upload_error_message(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('Uploaded file exceeds the allowed size.', 'hfe-to-songlist'),
            UPLOAD_ERR_PARTIAL => __('Uploaded file was only partially received.', 'hfe-to-songlist'),
            UPLOAD_ERR_NO_FILE => __('No file selected.', 'hfe-to-songlist'),
            UPLOAD_ERR_NO_TMP_DIR => __('Server is missing a temporary upload folder.', 'hfe-to-songlist'),
            UPLOAD_ERR_CANT_WRITE => __('Server could not write the uploaded file.', 'hfe-to-songlist'),
            UPLOAD_ERR_EXTENSION => __('A server extension blocked the upload.', 'hfe-to-songlist'),
            default => __('Upload failed.', 'hfe-to-songlist'),
        };
    }

    /**
     * @return array{success:false,error:string}
     */
    private function error_result(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }

}
