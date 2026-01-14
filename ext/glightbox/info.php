<?php

namespace Shimmie2;

final class GlightboxInfo extends ExtensionInfo
{
    public const string KEY = "glightbox";

    public string $name = "GLightbox";
    public array $authors = ["Luana Latte" => "luana.latte.cat@gmail.com"];
    public string $description = "Open images in a glightbox viewer";
    public ExtensionCategory $category = ExtensionCategory::GENERAL;
}
