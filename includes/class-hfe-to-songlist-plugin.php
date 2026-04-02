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
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function render_shortcode(array $atts = []): string
    {
        unset($atts);

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

        ob_start();
        ?>
        <div class="hfe-songlist-wrap">
            <?php echo $this->render_inline_styles(); ?>

            <?php if (is_array($result) && !empty($result['success'])) : ?>
                <?php
                /** @var array<int, array{filename:string,title:string}> $songs */
                $songs = is_array($result['songs'] ?? null) ? $result['songs'] : [];
                $songlist_text = is_string($result['songlist_text'] ?? null) ? $result['songlist_text'] : '';
                $album_name = is_string($result['album_name'] ?? null) ? $result['album_name'] : __('Unknown Album', 'hfe-to-songlist');
                $disk_kb = is_int($result['disk_kb'] ?? null) ? $result['disk_kb'] : 0;
                ?>
                <div class="hfe-songlist-message hfe-songlist-success">
                    <?php esc_html_e('Songlist generated successfully.', 'hfe-to-songlist'); ?>
                </div>

                <div class="hfe-songlist-result">
                    <p><strong><?php esc_html_e('Album:', 'hfe-to-songlist'); ?></strong> <?php echo esc_html($album_name); ?></p>
                    <?php if ($disk_kb > 0) : ?>
                        <p><strong><?php esc_html_e('Disk Type:', 'hfe-to-songlist'); ?></strong> <?php echo esc_html((string) $disk_kb); ?> KB</p>
                    <?php endif; ?>

                    <label for="hfe-songlist-text"><strong><?php esc_html_e('Copy-Friendly Output', 'hfe-to-songlist'); ?></strong></label>
                    <textarea id="hfe-songlist-text" class="hfe-songlist-textarea" readonly rows="12"><?php echo esc_textarea($songlist_text); ?></textarea>

                    <table class="hfe-songlist-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('#', 'hfe-to-songlist'); ?></th>
                            <th><?php esc_html_e('Song Title', 'hfe-to-songlist'); ?></th>
                            <th><?php esc_html_e('Source File', 'hfe-to-songlist'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($songs as $index => $song) : ?>
                            <tr>
                                <td><?php echo esc_html(sprintf('%02d', (int) $index)); ?></td>
                                <td><?php echo esc_html($song['title']); ?></td>
                                <td><code><?php echo esc_html($song['filename']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (is_array($result) && empty($result['success'])) : ?>
                <div class="hfe-songlist-message hfe-songlist-error">
                    <?php echo esc_html((string) ($result['error'] ?? __('Unable to process file.', 'hfe-to-songlist'))); ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="hfe-songlist-form">
                <?php wp_nonce_field('hfe_songlist_upload', 'hfe_songlist_nonce'); ?>
                <input type="hidden" name="hfe_songlist_action" value="upload">

                <label for="hfe-songlist-file"><strong><?php esc_html_e('Upload HFE File', 'hfe-to-songlist'); ?></strong></label>
                <input
                    id="hfe-songlist-file"
                    type="file"
                    name="hfe_songlist_file"
                    accept=".hfe,.HFE"
                    required
                >
                <p class="description"><?php esc_html_e('Only 720 KB and 1440 KB floppy disk HFE images are supported.', 'hfe-to-songlist'); ?></p>

                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Generate Songlist', 'hfe-to-songlist'); ?>
                </button>
            </form>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @return array{success:bool,error?:string,album_name?:string,disk_kb?:int,songs?:array<int,array{filename:string,title:string}>,songlist_text?:string}
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

        try {
            return $this->process_hfe_file($uploaded_hfe_path, $original_name, $work_dir);
        } finally {
            $this->delete_directory($work_dir);
        }
    }

    /**
     * @return array{success:bool,error?:string,album_name?:string,disk_kb?:int,songs?:array<int,array{filename:string,title:string}>,songlist_text?:string}
     */
    private function process_hfe_file(string $hfe_path, string $original_name, string $work_dir): array
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
        $songlist_text = $this->format_songlist_text($album_name, $songs);

        return [
            'success' => true,
            'album_name' => $album_name,
            'disk_kb' => $convert_result['disk_kb'],
            'songs' => $songs,
            'songlist_text' => $songlist_text,
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
     * @return array{success:bool,img_path?:string,disk_kb?:int}
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
                continue;
            }

            $expected_size = $this->expected_img_size_for_format($format);
            if ($expected_size <= 0 || filesize($img_path) !== $expected_size) {
                continue;
            }

            $list_result = $this->run_command([$this->seven_z_binary(), 'l', $img_path]);
            if (!$this->is_valid_fat_listing($list_result)) {
                continue;
            }

            return [
                'success' => true,
                'img_path' => $img_path,
                'disk_kb' => $format === 'ibm.1440' ? 1440 : 720,
            ];
        }

        return ['success' => false];
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
            $lines[] = sprintf('  %02d. %s', (int) $index, $song['title']);
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

    private function render_inline_styles(): string
    {
        return '<style>
.hfe-songlist-wrap{max-width:980px}
.hfe-songlist-form{display:grid;gap:10px;margin-top:12px}
.hfe-songlist-message{padding:10px 12px;border-radius:4px;margin:8px 0}
.hfe-songlist-success{background:#edf9ed;border:1px solid #93c593}
.hfe-songlist-error{background:#fff2f2;border:1px solid #e19a9a}
.hfe-songlist-textarea{width:100%;font-family:Consolas,Monaco,monospace;white-space:pre;min-height:220px}
.hfe-songlist-table{width:100%;border-collapse:collapse;margin-top:14px}
.hfe-songlist-table th,.hfe-songlist-table td{border:1px solid #dcdcdc;padding:7px 9px;text-align:left}
.hfe-songlist-table th{background:#f7f7f7}
</style>';
    }
}
