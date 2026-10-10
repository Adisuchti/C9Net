<?php
/**
 * Generates a thumbnail for a given image.
 * 
 * @param string $source Path to the source image.
 * @param string $destination Path where the thumbnail will be saved.
 * @param int $maxWidth Maximum width of the thumbnail.
 * @param int $maxHeight Maximum height of the thumbnail.
 * @return bool True on success, false on failure.
 */
function createThumbnail($source, $destination, $maxWidth = 150, $maxHeight = 150) {
    if (!file_exists($source)) {
        return false;
    }

    $imageInfo = getimagesize($source);
    if ($imageInfo === false) {
        return false;
    }

    list($originalWidth, $originalHeight, $imageType) = $imageInfo;

    // Calculate ratio
    $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
    
    // If the image is already smaller than the max dimensions, don't upscale
    if ($ratio > 1) {
        $ratio = 1;
    }

    $newWidth = (int)($originalWidth * $ratio);
    $newHeight = (int)($originalHeight * $ratio);

    $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
        imagecolortransparent($thumbnail, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
    }

    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = imagecreatefromgif($source);
            break;
        default:
            return false;
    }

    if (!$sourceImage) {
        return false;
    }

    imagecopyresampled(
        $thumbnail,
        $sourceImage,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $originalWidth, $originalHeight
    );

    $result = false;
    $destExt = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    
    switch ($destExt) {
        case 'jpg':
        case 'jpeg':
            $result = imagejpeg($thumbnail, $destination, 90);
            break;
        case 'png':
            $result = imagepng($thumbnail, $destination);
            break;
        case 'gif':
            $result = imagegif($thumbnail, $destination);
            break;
        default:
            if ($imageType == IMAGETYPE_JPEG) $result = imagejpeg($thumbnail, $destination, 90);
            elseif ($imageType == IMAGETYPE_PNG) $result = imagepng($thumbnail, $destination);
            elseif ($imageType == IMAGETYPE_GIF) $result = imagegif($thumbnail, $destination);
    }

    imagedestroy($sourceImage);
    imagedestroy($thumbnail);

    return $result;
}
?>
