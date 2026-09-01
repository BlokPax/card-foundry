<?php

// Build the macOS app icon: a deep-indigo squircle (dark-theme surface
// colors) with the Imagination Mining pickaxe+stars logomark filled with
// the brand gradient — matching the header logo. Rendered 2x and
// downsampled for smooth edges.

$base = '/Users/jim/work/ind4n-studio';
$out = $base.'/public/icon.png';

$S = 2048;                       // working canvas (downsampled to 1024)
$margin = (int) ($S * 0.098);    // Apple grid: icon ≈ 824/1024
$rect = $S - 2 * $margin;        // rounded-square size
$radius = (int) ($rect * 0.225); // ≈ Apple's corner radius

// Colors
$bgTop = [0x1c, 0x12, 0x3f];    // --color-panel (dark)
$bgBottom = [0x13, 0x0c, 0x2b]; // --color-surface (dark)
$gradFrom = [0x55, 0xb8, 0xec]; // --color-accent-from (dark)
$gradTo = [0xe9, 0x68, 0xd6];   // --color-accent-to (dark)

$canvas = imagecreatetruecolor($S, $S);
imagesavealpha($canvas, true);
imagealphablending($canvas, false);
imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
imagealphablending($canvas, true);

// Rounded rect with a vertical background gradient, row by row.
$x0 = $margin;
$y0 = $margin;

for ($dy = 0; $dy < $rect; $dy++) {
    $t = $dy / ($rect - 1);
    $r = (int) round($bgTop[0] + ($bgBottom[0] - $bgTop[0]) * $t);
    $g = (int) round($bgTop[1] + ($bgBottom[1] - $bgTop[1]) * $t);
    $b = (int) round($bgTop[2] + ($bgBottom[2] - $bgTop[2]) * $t);
    $color = imagecolorallocate($canvas, $r, $g, $b);

    // Inset within the corner arcs.
    $inset = 0;
    if ($dy < $radius) {
        $inset = $radius - (int) round(sqrt($radius ** 2 - ($radius - $dy) ** 2));
    } elseif ($dy >= $rect - $radius) {
        $d = $dy - ($rect - $radius);
        $inset = $radius - (int) round(sqrt($radius ** 2 - $d ** 2));
    }

    imageline($canvas, $x0 + $inset, $y0 + $dy, $x0 + $rect - 1 - $inset, $y0 + $dy, $color);
}

// The logomark, scaled to sit centered in the squircle.
$mark = imagecreatefrompng($base.'/public/images/logomark.png');
$mw = imagesx($mark);
$mh = imagesy($mark);

$targetH = (int) ($rect * 0.60);
$targetW = (int) ($targetH * $mw / $mh);

$scaled = imagecreatetruecolor($targetW, $targetH);
imagesavealpha($scaled, true);
imagealphablending($scaled, false);
imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
imagecopyresampled($scaled, $mark, 0, 0, 0, 0, $targetW, $targetH, $mw, $mh);

$offsetX = (int) (($S - $targetW) / 2);
$offsetY = (int) (($S - $targetH) / 2);

// Paint the mark's alpha with the diagonal brand gradient.
for ($y = 0; $y < $targetH; $y++) {
    for ($x = 0; $x < $targetW; $x++) {
        $alpha127 = (imagecolorat($scaled, $x, $y) >> 24) & 0x7f;
        if ($alpha127 >= 127) {
            continue;
        }

        $t = ($x + $y) / ($targetW + $targetH - 2);
        $r = (int) round($gradFrom[0] + ($gradTo[0] - $gradFrom[0]) * $t);
        $g = (int) round($gradFrom[1] + ($gradTo[1] - $gradFrom[1]) * $t);
        $b = (int) round($gradFrom[2] + ($gradTo[2] - $gradFrom[2]) * $t);

        imagesetpixel($canvas, $offsetX + $x, $offsetY + $y,
            imagecolorallocatealpha($canvas, $r, $g, $b, $alpha127));
    }
}

// Downsample to 1024 and save.
$final = imagecreatetruecolor(1024, 1024);
imagesavealpha($final, true);
imagealphablending($final, false);
imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
imagecopyresampled($final, $canvas, 0, 0, 0, 0, 1024, 1024, $S, $S);
imagepng($final, $out);

echo "Wrote {$out} (1024x1024)\n";
