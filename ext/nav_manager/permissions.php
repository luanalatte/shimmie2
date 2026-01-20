<?php

declare(strict_types=1);

namespace Shimmie2;

final class NavManagerPermission extends PermissionGroup
{
    public const string KEY = NavManagerInfo::KEY;

    #[PermissionMeta("Manage Navigation Links")]
    public const string MANAGE_NAVLINKS = "manage_navlinks";
}
