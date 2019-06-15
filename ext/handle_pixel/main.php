<?php
/**
 * Name: Handle Pixel
 * Author: Shish <webmaster@shishnet.org>
 * Link: http://code.shishnet.org/shimmie2/
 * Description: Handle JPEG, PNG, GIF, WEBP, etc files
 */

class PixelFileHandler extends DataHandlerExtension
{
    const SUPPORTED_EXTENSIONS = ["jpg", "jpeg", "gif", "png", "webp"];

    protected function supported_ext(string $ext): bool
    {
        $ext = (($pos = strpos($ext, '?')) !== false) ? substr($ext, 0, $pos) : $ext;
        return in_array(strtolower($ext), self::SUPPORTED_EXTENSIONS);
    }

    protected function create_image_from_data(string $filename, array $metadata)
    {
        $image = new Image();

        $info = getimagesize($filename);
        if (!$info) {
            return null;
        }

        $image->width = $info[0];
        $image->height = $info[1];

        $image->filesize  = $metadata['size'];
        $image->hash      = $metadata['hash'];
        $image->filename  = (($pos = strpos($metadata['filename'], '?')) !== false) ? substr($metadata['filename'], 0, $pos) : $metadata['filename'];
        $image->ext       = (($pos = strpos($metadata['extension'], '?')) !== false) ? substr($metadata['extension'], 0, $pos) : $metadata['extension'];
        $image->tag_array = is_array($metadata['tags']) ? $metadata['tags'] : Tag::explode($metadata['tags']);
        $image->source    = $metadata['source'];

        return $image;
    }

    protected function check_contents(string $tmpname): bool
    {
        $valid = [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
        if (!file_exists($tmpname)) {
            return false;
        }
        $info = getimagesize($tmpname);
        if (is_null($info)) {
            return false;
        }
        if (in_array($info[2], $valid)) {
            return true;
        }
        return false;
    }

    protected function create_thumb(string $hash, string $type): bool
    {
        global $config;

        $inname  = warehouse_path(Image::IMAGE_DIR, $hash);
        $outname = warehouse_path(Image::THUMBNAIL_DIR, $hash);

        $ok = false;

        switch ($config->get_string("thumb_engine")) {
            default:
            case 'gd':
                $ok = $this->make_thumb_gd($inname, $outname);
                break;
            case 'convert':
                $ok = create_thumbnail_convert($hash);
                break;
        }

        return $ok;
    }

    public function onImageAdminBlockBuilding(ImageAdminBlockBuildingEvent $event)
    {
        $event->add_part("
			<form>
				<select class='shm-zoomer'>
					<option value='full'>Full Size</option>
					<option value='width'>Fit Width</option>
					<option value='height'>Fit Height</option>
					<option value='both'>Fit Both</option>
				</select>
			</form>
		", 20);
    }

    // GD thumber {{{
    private function make_thumb_gd(string $inname, string $outname): bool
    {
        global $config;

        try {
            $info = getimagesize($inname);
            $tsize = get_thumbnail_size($info[0], $info[1], true);
            $image = image_resize_gd(
                $inname,
                $info,
                $tsize[0],
                $tsize[1],
                $outname,
                $config->get_string('thumb_type'),
                $config->get_int('thumb_quality')
            );
        } catch (InsufficientMemoryException $e) {
            $tsize = get_thumbnail_max_size_scaled();
            $thumb = imagecreatetruecolor($tsize[0], min($tsize[1], 64));
            $white = imagecolorallocate($thumb, 255, 255, 255);
            $black = imagecolorallocate($thumb, 0, 0, 0);
            imagefill($thumb, 0, 0, $white);
            log_warning("handle_pixel", "Insufficient memory while creating thumbnail: ".$e->getMessage());
            imagestring($thumb, 5, 10, 24, "Image Too Large :(", $black);
            return true;
        } catch (Exception $e) {
            log_error("handle_pixel", "Error while creating thumbnail: ".$e->getMessage());
            return false;
        }

        return true;
    }
    // }}}
}
