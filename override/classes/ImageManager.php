<?php
/**
 * 2016 Michael Dekker
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@michaeldekker.com so we can send you a copy immediately.
 *
 *  @author    Michael Dekker <prestashop@michaeldekker.com>
 *  @copyright 2016 Michael Dekker
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

class ImageManager extends ImageManagerCore
{
    public static function resize($src_file, $dst_file, $dst_width = null, $dst_height = null, $file_type = 'jpg',
        $force_type = false, &$error = 0, &$tgt_width = null, &$tgt_height = null, $quality = 5,
        &$src_width = null, &$src_height = null)
    {
        if (!Module::isEnabled('mdimagemagick')) {
            return parent::resize($src_file, $dst_file, $dst_width, $dst_height, $file_type,
                $force_type, $error, $tgt_width , $tgt_height, $quality, $src_width, $src_height);
        }
        if (!class_exists('MDImageMagick')) {
            require_once _PS_MODULE_DIR_.'mdimagemagick/mdimagemagick.php';
        }

        if (Configuration::get(MDImageMagick::ORIGINAL_COPY)) {
            // Check if we should just copy the file instead
            $relative_dst_file = str_replace(_PS_IMG_DIR_, '', $dst_file);
            $dst_file_only = end(explode(DIRECTORY_SEPARATOR, $relative_dst_file));
            list($filename, $extension) = explode('.', $dst_file_only);

            if (is_numeric($filename) && preg_match('/^(c|p|m|su|st)'.preg_quote(DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, str_split($filename)).DIRECTORY_SEPARATOR.$filename.'.'.$extension, '/').'$/', $relative_dst_file)) {
                return @copy($src_file, $dst_file);
            }
        }

        $imagick_enabled = (bool)Configuration::get(MDImageMagick::IMAGICK_ENABLED);
        if ($imagick_enabled && !extension_loaded('imagick')) {
            Db::getInstance()->update('configuration', array('name' => MDImageMagick::IMAGICK_ENABLED, 'value' => false), 'name = \''.MDImageMagick::IMAGICK_ENABLED.'\'');
            $imagick_enabled = false;
        }

        if (PHP_VERSION_ID < 50300) {
            clearstatcache();
        } else {
            clearstatcache(true, $src_file);
        }

        if (!file_exists($src_file) || !filesize($src_file)) {
            return !($error = self::ERROR_FILE_NOT_EXIST);
        }

        list($tmp_width, $tmp_height, $type) = getimagesize($src_file);
        $rotate = 0;
        if (function_exists('exif_read_data') && function_exists('mb_strtolower')) {
            $exif = @exif_read_data($src_file);

            if ($exif && isset($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3:
                        $src_width = $tmp_width;
                        $src_height = $tmp_height;
                        $rotate = 180;
                        break;

                    case 6:
                        $src_width = $tmp_height;
                        $src_height = $tmp_width;
                        $rotate = -90;
                        break;

                    case 8:
                        $src_width = $tmp_height;
                        $src_height = $tmp_width;
                        $rotate = 90;
                        break;

                    default:
                        $src_width = $tmp_width;
                        $src_height = $tmp_height;
                }
            } else {
                $src_width = $tmp_width;
                $src_height = $tmp_height;
            }
        } else {
            $src_width = $tmp_width;
            $src_height = $tmp_height;
        }

        // If PS_IMAGE_QUALITY is activated, the generated image will be a PNG with .jpg as a file extension.
        // This allow for higher quality and for transparency. JPG source files will also benefit from a higher quality
        // because JPG reencoding by GD, even with max quality setting, degrades the image.
        if (Configuration::get('PS_IMAGE_QUALITY') == 'png_all'
            || (Configuration::get('PS_IMAGE_QUALITY') == 'png' && $type == IMAGETYPE_PNG) && !$force_type) {
            $file_type = 'png';
        }

        if (!$src_width) {
            return !($error = self::ERROR_FILE_WIDTH);
        }
        if (!$dst_width) {
            $dst_width = $src_width;
        }
        if (!$dst_height) {
            $dst_height = $src_height;
        }

        $width_diff = $dst_width / $src_width;
        $height_diff = $dst_height / $src_height;

        $ps_image_generation_method = Configuration::get('PS_IMAGE_GENERATION_METHOD');
        if ($width_diff > 1 && $height_diff > 1) {
            $next_width = $src_width;
            $next_height = $src_height;
        } else {
            if ($ps_image_generation_method == 2 || (!$ps_image_generation_method && $width_diff > $height_diff)) {
                $next_height = $dst_height;
                $next_width = round(($src_width * $next_height) / $src_height);
                $dst_width = (int)(!$ps_image_generation_method ? $dst_width : $next_width);
            } else {
                $next_width = $dst_width;
                $next_height = round($src_height * $dst_width / $src_width);
                $dst_height = (int)(!$ps_image_generation_method ? $dst_height : $next_height);
            }
        }

        if (!ImageManager::checkImageMemoryLimit($src_file)) {
            return !($error = self::ERROR_MEMORY_LIMIT);
        }

        if ($imagick_enabled) {
            $src_image = new Imagick();
            $src_image->readImage($src_file);
            if ($file_type == 'png') {
                // PNG is basically no more than lossless gzip
                $src_image->setImageCompression(Imagick::COMPRESSION_LZW);
                $src_image->setImageFormat('png');
                $src_image->setImageCompressionQuality((int)Configuration::get('PS_PNG_QUALITY') * 10 + (int)Configuration::get(MDImageMagick::IMAGICK_PNG_DATA_ENCODING));
                $dest_type_file = 'png:'.$dst_file;
            } else {
                $src_image->setImageCompression(Imagick::COMPRESSION_JPEG);
                if (Configuration::get(MDImageMagick::IMAGICK_PROGRESSIVE_JPEG)) {
                    $src_image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                } else {
                    $src_image->setInterlaceScheme(Imagick::INTERLACE_LINE);
                }
                $src_image->setImageCompressionQuality((int)Configuration::get('PS_JPEG_QUALITY'));
                $dest_type_file = $dst_file;
            }

            if (Configuration::get(MDImageMagick::IMAGICK_TRIM_WHITESPACE)) {
                $fuzz = (float)Configuration::get(MDImageMagick::IMAGICK_FUZZ, 0);
                // From percentage to 0 - 1 float
                $fuzz = $fuzz / 100.00000;

                // Calculate before dimensions and ratio
                $before_width = (float)$src_image->getImageWidth();
                $before_height = (float)$src_image->getImageHeight();
                $ratio = (float)($before_width / $before_height);

                // Trim whitespace
                $src_image->trimImage($fuzz);

                // Restore ratio
                if ($ratio > 1) {
                    $src_image->extentImage($before_width, $before_height, 0, -(($before_height - $src_image->getImageHeight()) / 2));
                } else {
                    $src_image->extentImage($before_width, $before_height, -(($before_width - $src_image->getImageWidth()) / 2), 0);
                }
            }

            if (Configuration::get(MDImageMagick::IMAGICK_STRIP_ICC_PROFILE)) {
                // Transform to sRGB
                $src_image->transformImageColorspace(Imagick::COLORSPACE_SRGB);

                // Strip ICC profiles, comments and exif data
                $src_image->stripImage();

                // Restore orientation
                if ($rotate) {
                    $src_image->rotateImage("#fff", $rotate);
                }
            }

            Hook::exec(
                'actionChangeImagickSettings',
                array(
                    'imagick' => &$src_image,
                    'src_file' => $src_file,
                    'dst_file' => $dst_file,
                    'dst_width' => $dst_width,
                    'dst_height' => $dst_height,
                    'file_type' => $file_type,
                )
            );

            // Do we even need to resize?
            if ($dst_width < $src_image->getImageWidth() || $dst_height < $src_image->getImageHeight() || Configuration::get(MDImageMagick::IMAGICK_TRIM_WHITESPACE)) {
                $src_image->resizeImage($dst_width, $dst_height, Configuration::get(MDImageMagick::IMAGICK_FILTER), Configuration::get(MDImageMagick::IMAGICK_BLUR), true);
            }

            // If the image dimensions differ from the target, add whitespace
            // Begin with the height...
            if ($dst_height > $src_image->getImageHeight()) {
                $src_image->extentImage($src_image->getImageWidth(), $dst_height, 0, -(($dst_height - $src_image->getImageHeight()) / 2));
            }
            // ...and then the width, if necessary
            if ($dst_width > $src_image->getImageWidth()) {
                $src_image->extentImage($dst_width, $src_image->getImageHeight(), -(($dst_width - $src_image->getImageWidth()) / 2), 0);
            }

            $write_file = $src_image->writeImage($dest_type_file);
            Hook::exec('actionOnImageResizeAfter', array('dst_file' => $dst_file, 'file_type' => $file_type));

            return $write_file;
        } else {
            $tgt_width  = $dst_width;
            $tgt_height = $dst_height;

            $dest_image = imagecreatetruecolor($dst_width, $dst_height);

            // If image is a PNG and the output is PNG, fill with transparency. Else fill with white background.
            if ($file_type == 'png' && $type == IMAGETYPE_PNG) {
                imagealphablending($dest_image, false);
                imagesavealpha($dest_image, true);
                $transparent = imagecolorallocatealpha($dest_image, 255, 255, 255, 127);
                imagefilledrectangle($dest_image, 0, 0, $dst_width, $dst_height, $transparent);
            } else {
                $white = imagecolorallocate($dest_image, 255, 255, 255);
                imagefilledrectangle($dest_image, 0, 0, $dst_width, $dst_height, $white);
            }

            $src_image = ImageManager::create($type, $src_file);
            if ($rotate) {
                $src_image = imagerotate($src_image, $rotate, 0);
            }

            if ($dst_width >= $src_width && $dst_height >= $src_height) {
                imagecopyresized($dest_image, $src_image, (int)(($dst_width - $next_width) / 2), (int)(($dst_height - $next_height) / 2), 0, 0, $next_width, $next_height, $src_width, $src_height);
            } else {
                ImageManager::imagecopyresampled($dest_image, $src_image, (int)(($dst_width - $next_width) / 2), (int)(($dst_height - $next_height) / 2), 0, 0, $next_width, $next_height, $src_width, $src_height, $quality);
            }
            $write_file = ImageManager::write($file_type, $dest_image, $dst_file);
            Hook::exec('actionOnImageResizeAfter', array('dst_file' => $dst_file, 'file_type' => $file_type));
            @imagedestroy($src_image);
            return $write_file;
        }
    }
}
