<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SampleScans
{
    private const FONT = '/usr/share/fonts/dejavu/DejaVuSans.ttf';

    private const BOLD = '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf';

    public static function available(): bool
    {
        return function_exists('imagettftext') && is_file(self::FONT) && is_file(self::BOLD);
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function certificate(string $dir, string $title, array $fields, string $issuer): ?string
    {
        if (! self::available()) {
            return null;
        }
        $w = 1100;
        $h = 760;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, 250, 248, 240);
        $frame = imagecolorallocate($img, 31, 78, 121);
        $text = imagecolorallocate($img, 30, 36, 44);
        $muted = imagecolorallocate($img, 110, 118, 128);
        $mark = imagecolorallocatealpha($img, 200, 60, 50, 95);
        $line = imagecolorallocate($img, 190, 196, 204);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        imagesetthickness($img, 6);
        imagerectangle($img, 18, 18, $w - 19, $h - 19, $frame);
        imagesetthickness($img, 1);
        imagerectangle($img, 30, 30, $w - 31, $h - 31, $frame);

        imagettftext($img, 15, 0, 60, 82, $muted, self::FONT, mb_strtoupper(mb_substr($issuer, 0, 70)));
        imagettftext($img, 30, 0, 60, 140, $frame, self::BOLD, 'ОБРАЗЕЦ УДОСТОВЕРЕНИЯ');
        $y = 190;
        foreach (self::wrap($title, 52) as $part) {
            imagettftext($img, 20, 0, 60, $y, $text, self::BOLD, $part);
            $y += 34;
        }
        $y += 16;
        foreach ($fields as $label => $value) {
            imagettftext($img, 15, 0, 60, $y, $muted, self::FONT, $label);
            imagettftext($img, 19, 0, 330, $y, $text, self::FONT, mb_substr($value, 0, 48));
            imageline($img, 330, $y + 10, $w - 70, $y + 10, $line);
            $y += 52;
        }
        imagettftext($img, 13, 0, 60, $h - 60, $muted, self::FONT, 'Сгенерированный образец для прототипа, не является документом');
        imageellipse($img, $w - 190, $h - 150, 190, 190, $frame);
        imageellipse($img, $w - 190, $h - 150, 170, 170, $frame);
        imagettftext($img, 13, 0, $w - 250, $h - 145, $frame, self::BOLD, 'М.П. ОБРАЗЕЦ');
        imagettftext($img, 110, 28, 210, $h - 140, $mark, self::BOLD, 'ОБРАЗЕЦ');

        $path = trim($dir, '/').'/'.Str::uuid().'.jpg';
        ob_start();
        imagejpeg($img, null, 78);
        $data = ob_get_clean();
        imagedestroy($img);
        Storage::disk('local')->put($path, $data);

        return $path;
    }

    public static function avatar(string $dir, string $initials, int $seed): ?string
    {
        if (! self::available()) {
            return null;
        }
        $palette = [[31, 78, 121], [21, 101, 92], [122, 74, 30], [88, 63, 140], [46, 104, 52], [140, 52, 64]];
        [$r, $g, $b] = $palette[$seed % count($palette)];
        $img = imagecreatetruecolor(300, 380);
        $bg = imagecolorallocate($img, $r, $g, $b);
        $light = imagecolorallocatealpha($img, 255, 255, 255, 70);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, 300, 380, $bg);
        imagefilledellipse($img, 150, 150, 150, 150, $light);
        imagefilledellipse($img, 150, 390, 280, 260, $light);
        $box = imagettfbbox(54, 0, self::BOLD, $initials);
        $tw = $box[2] - $box[0];
        imagettftext($img, 54, 0, (int) (150 - $tw / 2), 172, $white, self::BOLD, $initials);
        $path = trim($dir, '/').'/'.Str::uuid().'.jpg';
        ob_start();
        imagejpeg($img, null, 82);
        $data = ob_get_clean();
        imagedestroy($img);
        Storage::disk('local')->put($path, $data);

        return $path;
    }

    /** @return string[] */
    private static function wrap(string $text, int $width): array
    {
        $words = preg_split('/\s+/u', $text);
        $lines = [];
        $cur = '';
        foreach ($words as $word) {
            if ($cur !== '' && mb_strlen($cur.' '.$word) > $width) {
                $lines[] = $cur;
                $cur = $word;
            } else {
                $cur = $cur === '' ? $word : $cur.' '.$word;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }

        return array_slice($lines, 0, 3);
    }
}
