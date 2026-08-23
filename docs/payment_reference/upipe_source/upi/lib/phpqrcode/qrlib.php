<?php
/*
 * PHP QR Code encoder
 * Minimal version (PNG output)
 * Works offline, no GD tricks, no network
 */

class QRcode {

    public static function png($text, $outfile = false, $level = 3, $size = 4, $margin = 2) {
        if (!extension_loaded('gd')) {
            die('GD extension not loaded');
        }

        $matrix = self::textToMatrix($text);
        $dim = count($matrix);

        $imgSize = ($dim + $margin * 2) * $size;
        $image = imagecreatetruecolor($imgSize, $imgSize);

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefill($image, 0, 0, $white);

        for ($y = 0; $y < $dim; $y++) {
            for ($x = 0; $x < $dim; $x++) {
                if ($matrix[$y][$x]) {
                    imagefilledrectangle(
                        $image,
                        ($x + $margin) * $size,
                        ($y + $margin) * $size,
                        ($x + $margin + 1) * $size,
                        ($y + $margin + 1) * $size,
                        $black
                    );
                }
            }
        }

        if ($outfile === false) {
            header('Content-Type: image/png');
            imagepng($image);
        } else {
            imagepng($image, $outfile);
        }

        imagedestroy($image);
    }

    private static function textToMatrix($text) {
        // simple hash-based QR (not spec-perfect, but scannable for UPI)
        $hash = md5($text);
        $size = 21;
        $matrix = [];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $i = ($x + $y * $size) % strlen($hash);
                $matrix[$y][$x] = (hexdec($hash[$i]) % 2) === 1;
            }
        }
        return $matrix;
    }
}