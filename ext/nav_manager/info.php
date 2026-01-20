<?php

declare(strict_types=1);

namespace Shimmie2;

final class NavManagerInfo extends ExtensionInfo
{
    public const string KEY = "nav_manager";

    public string $name = "Navigation Manager";
    public array $authors = ["Luana Latte" => "mailto:luana.latte.cat@gmail.com"];
    public string $license = self::LICENSE_GPLV2;
    public string $description = "Move, rename, and toggle links.";
    public bool $core = false;
}
