<?php

/**
 * Tool "fule_sing": Louis Dé Fule hát tặng khách một bài trong playlist (tầng service).
 * Tool không đọc DB; nó chọn bài rồi trả lệnh để FE mở trang sân khấu /fule-sing và phát video.
 */
class GeminiToolFuleSing extends GeminiTool
{
    // Playlist: "tên bài hát" => tên file video trong thư mục BE/assets/fule/songs/.
    // Thêm bài mới: chép file video vào thư mục đó rồi thêm một dòng ở đây.
    // Tên file chỉ dùng chữ không dấu, số, gạch ngang, gạch dưới, dấu chấm (FE kiểm tra lại định dạng này).
    const PLAYLIST = [
        'Vì con' => 'vi-con.mp4',
    ];

    // Độ dài tối đa của tên bài hát do AI gửi lên (ký tự).
    const MAX_SONG_LENGTH = 100;

    /**
     * Tên tool. Gemini dùng tên này khi yêu cầu gọi.
     *
     * @return string
     */
    public function name()
    {
        return 'fule_sing';
    }

    /**
     * Mô tả để Gemini biết lúc nào nên gọi tool.
     *
     * @return string
     */
    public function description()
    {
        return 'Louis Dé Fule hát tặng khách một bài hát. Dùng khi khách muốn nghe hát, nghe nhạc, '
            . 'nhờ Louis/Fule hát, hoặc muốn giải trí cho bớt căng thẳng. '
            . 'Có thể chọn bài theo tên; nếu khách không nói tên bài thì để trống để hát ngẫu nhiên.';
    }

    /**
     * Khai báo tham số: tên bài hát, không bắt buộc.
     *
     * @return array
     */
    public function parameters()
    {
        return [
            'type' => 'object',
            'properties' => [
                'song' => [
                    'type' => 'string',
                    'description' => 'Tên bài hát khách muốn nghe. Để trống nếu khách không chỉ định bài.',
                ],
            ],
        ];
    }

    /**
     * Chọn bài hát rồi trả lệnh mở trang sân khấu cho FE.
     *
     * @param array $args    Tham số Gemini gửi lên, có thể có 'song' (chuỗi, tối đa 100 ký tự)
     * @param array $context Ngữ cảnh server (không dùng)
     * @return array ['found' => bool, 'song' => tên bài, 'message' => ..., 'playlist' => danh sách tên bài]
     * @throws InvalidArgumentException Khi song không phải chuỗi hoặc quá dài
     */
    public function execute($args = [], $context = [])
    {
        $args = is_array($args) ? $args : [];

        $song = $args['song'] ?? '';
        if (!is_string($song)) {
            throw new InvalidArgumentException('Tên bài hát phải là chuỗi.');
        }
        $song = trim($song);
        if (mb_strlen($song, 'UTF-8') > self::MAX_SONG_LENGTH) {
            throw new InvalidArgumentException('Tên bài hát quá dài, tối đa ' . self::MAX_SONG_LENGTH . ' ký tự.');
        }

        $titles = array_keys(self::PLAYLIST);
        if ($titles === []) {
            return ['found' => false, 'message' => 'Playlist của Louis Dé Fule hiện đang trống.', 'playlist' => []];
        }

        if ($song === '') {
            // random_int: chọn ngẫu nhiên một vị trí trong playlist (từ 0 đến số bài - 1).
            $title = $titles[random_int(0, count($titles) - 1)];
        } else {
            $title = $this->findTitle($song, $titles);
            if ($title === null) {
                // Không tìm thấy bài thì không mở trang, để Gemini nói lại và gợi ý các bài đang có.
                return [
                    'found' => false,
                    'message' => 'Louis Dé Fule chưa thuộc bài "' . $song . '".',
                    'playlist' => $titles,
                ];
            }
        }

        return [
            'found' => true,
            'song' => $title,
            'message' => 'Website đang mở sân khấu, khách bấm nút "Nghe Louis hát" để phát bài.',
            'playlist' => $titles,
            Gemini::ACTION_KEY => $this->navigateAction('fule-sing', [
                'song' => $title,
                'video' => self::PLAYLIST[$title],
            ]),
        ];
    }

    /**
     * Tìm tên bài trong playlist, không phân biệt hoa thường.
     * Ưu tiên khớp đúng tên, sau đó mới tới khớp một phần (ví dụ khách gõ "vì con" hoặc "bài vì con").
     *
     * @param string $song   Tên bài khách yêu cầu
     * @param array  $titles Danh sách tên bài trong playlist
     * @return string|null Tên bài đúng như trong playlist, hoặc null nếu không có
     */
    private function findTitle($song = '', $titles = [])
    {
        // mb_strtolower: chuyển chữ thường đúng với chữ có dấu tiếng Việt (strtolower thì không).
        $needle = mb_strtolower($song, 'UTF-8');

        foreach ($titles as $title) {
            if (mb_strtolower($title, 'UTF-8') === $needle) {
                return $title;
            }
        }
        foreach ($titles as $title) {
            $lowerTitle = mb_strtolower($title, 'UTF-8');
            if (str_contains($needle, $lowerTitle) || str_contains($lowerTitle, $needle)) {
                return $title;
            }
        }

        return null;
    }
}
