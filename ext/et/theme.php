<?php

class ETTheme extends Themelet
{
    /*
     * Create a page showing info
     *
     * $info = an array of ($name => $value)
     */
    public function display_info_page($info)
    {
        global $page;

        $page->set_title("System Info");
        $page->set_heading("System Info");
        $page->add_block(new NavBlock());
        $page->add_block(new Block("Information:", $this->build_data_form($info)));
    }

    protected function build_data_form($info)
    {
        $data = <<<EOD
Optional:
Site title: {$info['site_title']}
Theme: {$info['site_theme']}
Genre: [describe your site here]
URL: {$info['site_url']}

System stats:
Shimmie: {$info['sys_shimmie']}
Schema: {$info['sys_schema']}
PHP: {$info['sys_php']}
OS: {$info['sys_os']}
Database: {$info['sys_db']}
Server: {$info['sys_server']}
Disk use: {$info['sys_disk']}

Graphics System:
Memory Limit: {$info[GraphicsConfig::MEM_LIMIT]}

Thumbnail Generation:
Engine: {$info[ImageConfig::THUMB_ENGINE]}
Type: {$info[ImageConfig::THUMB_TYPE]}
Quality: {$info[ImageConfig::THUMB_QUALITY]}
Width: {$info[ImageConfig::THUMB_WIDTH]}
Height: {$info[ImageConfig::THUMB_HEIGHT]}
Scaling: {$info[ImageConfig::THUMB_SCALING]}

Shimmie stats:
Images: {$info['stat_images']}
Comments: {$info['stat_comments']}
Users: {$info['stat_users']}
Tags: {$info['stat_tags']}
Applications: {$info['stat_image_tags']}
Extensions: {$info['sys_extensions']}
EOD;
        $html = <<<EOD
<form action='http://shimmie.shishnet.org/register.php' method='POST'>
	<input type='hidden' name='registration_api' value='1'>
	<textarea name='data' rows='20' cols='80'>$data</textarea>
	<br><input type='submit' value='Click to send to Shish'>
	<br>Your stats are useful so that I know which combinations
	of web servers / databases / etc I need to support.
</form>
EOD;
        return $html;
    }
}
