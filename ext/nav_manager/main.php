<?php

declare(strict_types=1);

namespace Shimmie2;

use GQLA\{Field, Type};
use MicroCRUD\{ActionColumn, Column, IntegerColumn, Table, TextColumn};
use MicroHTML\HTMLElement;

use function MicroHTML\{INPUT, OPTION, SELECT};

final class NavlinkUpdateEvent extends Event
{
    public function __construct(
        public LinkData $data
    ) {
        parent::__construct();
    }
}


final class ButtonColumn extends ActionColumn
{
    public function display(array $row): HTMLElement|string
    {
        if ($this->table->update_url) {
            return SHM_FORM(
                make_link((string) $this->table->update_url),
                method: "GET",
                children: [
                    INPUT(["type" => "hidden", "name" => $this->name, "value" => $row[$this->name]]),
                    SHM_SUBMIT("Edit")
                ]
            );
        }

        return "";
    }
}

final class BooleanColumn extends Column
{
    public function __construct(string $name, string $title, public bool $inverse = false)
    {
        parent::__construct($name, $title);
    }

    public function read_input(array $inputs): HTMLElement
    {
        $value = @$inputs["r_{$this->name}"];
        return SELECT(
            ["name" => "r_$this->name", "onchange" => "this.form.submit();"],
            OPTION(["value" => "", ...$value === "" ? ["selected" => "selected"] : []], "Any"),
            ...$this->inverse ? [
                OPTION(["value" => "0", ...$value === "0" ? ["selected" => "selected"] : []], "Yes"),
                OPTION(["value" => "1", ...$value === "1" ? ["selected" => "selected"] : []], "No"),
            ] : [
                OPTION(["value" => "0", ...$value === "0" ? ["selected" => "selected"] : []], "No"),
                OPTION(["value" => "1", ...$value === "1" ? ["selected" => "selected"] : []], "Yes"),
            ]
        );
    }

    public function display(array $row): string
    {
        if ($this->inverse) {
            return ($row[$this->name] ? 'No' : 'Yes');
        }

        return ($row[$this->name] ? 'Yes' : 'No');
    }
}

final class NavLinkKeyColumn extends Column
{
    public function display(array $row): HTMLElement|string
    {
        return explode("::", $row[$this->name] ?? "", 2)[1] ?? $row[$this->name];
    }
}

final class NavlinkTable extends Table
{
    public function __construct(\FFSPHP\PDO $db)
    {
        parent::__construct($db);
        $this->table = "nav_manager";
        $this->base_query = "
            SELECT
                a.id,
                a.description AS a_description,
                b.description AS b_description,
                a.url AS a_url,
                a.sort_order AS a_sort_order,
                a.enabled AS a_enabled,
                a.modified AS a_modified
            FROM nav_manager AS a
            LEFT JOIN nav_manager AS b ON b.id = a.parent_id
        ";
        $this->primary_key = "id";
        $this->size = 100;
        $this->set_columns([
            new TextColumn("a_description", "Description"),
            new TextColumn("b_description", "Parent"),
            new TextColumn("a_url", "URL"),
            new IntegerColumn("a_sort_order", "Order"),
            new BooleanColumn("a_enabled", "Enabled"),
            new BooleanColumn("a_modified", "Default", inverse: true),
            new ButtonColumn("id"),
        ]);

        $this->order_by = ["COALESCE(b.sort_order, a.sort_order)"];
        $this->table_attrs = ["class" => "zebra form"];
    }
}

#[Type(name: "LinkData")]
final class LinkData
{
    public ?int $id = null;
    public ?int $parent_id = null;
    #[Field]
    public string $description;
    #[Field]
    public Url $url;
    #[Field]
    public int $sort_order = 50;
    public bool $is_default = true;
    public ?string $key = null;
    public ?string $parent_key = null;
    #[Field]
    public bool $enabled = true;
    public bool $modified = false;

    public ?string $current_parent;

    /**
     * @param array{
     *     id: string|int,
     *     parent_id: string|int|null,
     *     description: string,
     *     url: string,
     *     sort_order: string|int,
     *     is_default: string|bool,
     *     key: string|null,
     *     parent_key: string|null,
     *     enabled: string|bool,
     *     modified: string|bool,
     *     current_parent: string|null,
     * }|null $row
     */
    public function __construct(?array $row = null)
    {
        if ($row === null) {
            return;
        }

        $this->id = (int) $row['id'];
        $this->parent_id = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        $this->description = $row['description'];
        $this->url = make_link($row['url']);
        $this->sort_order = (int) $row['sort_order'];
        $this->is_default = (bool) $row['is_default'];
        $this->key = $row['key'];
        $this->parent_key = $row['parent_key'];
        $this->enabled = (bool) $row['enabled'];
        $this->modified = (bool) $row['modified'];
        $this->current_parent = $row['current_parent'];
    }

    #[Field(name: "parent")]
    public function get_parent(): ?self
    {
        if ($this->parent_id === null) {
            return null;
        }

        return NavManager::get_link_by_id($this->parent_id);
    }

    public static function fromNavLink(NavLink $nav_link): self
    {
        $instance = new self();
        $instance->url = $nav_link->link;
        $instance->description = (string) $nav_link->description;
        $instance->sort_order = $nav_link->order;
        $instance->key = $nav_link->key;
        $instance->parent_key = $nav_link->parent;

        return $instance;
    }
}

/** @extends Extension<NavManagerTheme> */
final class NavManager extends Extension
{
    public const string KEY = NavManagerInfo::KEY;

    public function get_priority(): int
    {
        //before 404
        return 98;
    }

    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event): void
    {
        $database = Ctx::$database;

        if ($this->get_version() < 1) {
            $database->create_table("nav_manager", "
                    id SCORE_AIPK,
                    parent_id INTEGER,
                    description TEXT,
                    url TEXT,
                    sort_order INTEGER,
                    is_default INTEGER,
                    key VARCHAR(128),
                    parent_key VARCHAR(128),
                    enabled INTEGER DEFAULT 1,
                    modified INTEGER DEFAULT 0,
                    FOREIGN KEY (parent_id) REFERENCES nav_manager(id) ON DELETE SET NULL,
                ");

            $this->set_version(1);

            Log::info(NavManagerInfo::KEY, "extension installed");
        }
    }

    public function onPageRequest(PageRequestEvent $event): void
    {
        if (!Ctx::$user->can(NavManagerPermission::MANAGE_NAVLINKS)) {
            return;
        }

        $page = Ctx::$page;

        if ($event->page_matches("nav_manager/list")) {
            $t = new NavlinkTable(Ctx::$database->raw_db());
            $t->token = Ctx::$user->get_auth_token();
            $t->inputs = $event->GET->toArray();
            // @phpstan-ignore-next-line
            if (Ctx::$user->can(NavManagerPermission::MANAGE_NAVLINKS)) {
                $t->update_url = "nav_manager/edit";
            }
            $this->theme->display_table($t->table($t->query()), $t->paginator());
            return;
        }

        if ($event->page_matches("nav_manager/edit")) {
            $id = (int) $event->GET->req("id");
            $this->display_edit_form($id);
            return;
        }

        if ($event->page_matches("nav_manager/save", "POST")) {
            $data = new LinkData();
            $data->id = int_escape($event->POST->get("id"));
            $data->parent_id = nullify(int_escape($event->POST->get("parent_id")));
            $data->description = $event->POST->req("description");
            $data->url = make_link(mb_ltrim($event->POST->req("url"), "/"));
            $data->sort_order = int_escape($event->POST->get("sort_order"));
            $data->enabled = bool_escape($event->POST->get("enabled") ?? false);
            $data->modified = true;
            send_event(new NavlinkUpdateEvent($data));
            $page->flash("Link saved.");
            $page->set_redirect(make_link("nav_manager/list"));
            return;
        }

        if ($event->page_matches("nav_manager/restore", "POST")) {
            $id = $event->POST->req("id");
            if ($this->restore_link((int) $id)) {
                $page->flash("Link restored.");
                $page->set_redirect(make_link("nav_manager/list"));
            }
            return;
        }

        if ($event->page_matches("nav_manager/import")) {
            $this->import_new_links();
            $page->flash("Default navigation links imported.");
            $page->set_redirect(make_link("nav_manager/list"));
            return;
        }
    }

    public function onPageNavBuilding(PageNavBuildingEvent $event): void
    {
        $this->modify_links($event);
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event): void
    {
        if ($event->parent === "system") {
            $event->add_nav_link(make_link("nav_manager/list"), "Navigation Manager", "nav_manager", ["nav_manager"]);
        }

        $this->modify_links($event);
    }

    private function modify_links(PageNavBuildingEvent|PageSubNavBuildingEvent $event): void
    {
        if ($event->sender === self::class) {
            // Avoid altering links that are being requested by this extension's import feature.
            return;
        }

        $parent = $event instanceof PageSubNavBuildingEvent ? $event->parent : null;

        $data = $this->get_modified_links($parent);

        $links = [];
        foreach ($event->links as $link) {
            if (isset($data[$parent]) && isset($data[$parent][$link->key])) {
                $link_data = $data[$parent][$link->key];

                if (!$link_data->enabled || $link_data->current_parent !== $parent) {
                    if ($event->active_link !== null && $link->key === $event->active_link->key) {
                        $event->active_link = null;
                    }
                    continue;
                }

                $link->link = $link_data->url;
                $link->description = $link_data->description;
                $link->order = $link_data->sort_order;

                unset($data[$parent][$link->key]);
            }

            $links[] = $link;
        }

        $event->links = $links;

        foreach ($data as $new_links) {
            foreach ($new_links as $link_data) {
                if (!$link_data->enabled || $link_data->current_parent !== $parent) {
                    continue;
                }

                $event->add_nav_link($link_data->url, $link_data->description, $link_data->key ?? "nav_manager-$link_data->id", order: $link_data->sort_order);
            }
        }
    }

    public static function get_link_by_id(int $id): ?LinkData
    {
        $row = Ctx::$database->get_row("SELECT * FROM nav_manager WHERE id = :id", ["id" => $id]);
        if ($row === null) {
            return null;
        }

        // @phpstan-ignore-next-line
        return new LinkData($row);
    }

    public static function get_link_by_key(string $key, ?string $parent_key = null): ?LinkData
    {
        if ($parent_key === null) {
            $row = Ctx::$database->get_row("SELECT * FROM nav_manager WHERE key = :key", ["key" => $key]);
        } else {
            $row = Ctx::$database->get_row("SELECT * FROM nav_manager WHERE key = :key AND parent_key = :parent", ["key" => $key, "parent" => $parent_key]);
        }

        if ($row === null) {
            return null;
        }

        // @phpstan-ignore-next-line
        return new LinkData($row);
    }

    /**
     * @return array<string,array<string,LinkData>>
     */
    private function get_modified_links(?string $parent = null): array
    {
        $query = "
            SELECT a.*, b.key AS current_parent FROM nav_manager AS a
            LEFT JOIN nav_manager AS b ON b.id = a.parent_id
            WHERE a.modified = 1";
        $args = [];

        if ($parent !== null) {
            $query .= " AND (a.parent_key = :parent OR b.key = :parent)";
            $args["parent"] = $parent;
        } else {
            $query .= " AND a.parent_id IS NULL";
        }

        $result = [];
        foreach (Ctx::$database->get_all_iterable($query, $args) as $data) {
            $data = new LinkData($data);

            if ($data->parent_key !== null && !isset($result[$data->parent_key])) {
                $result[$data->parent_key] = [];
            }

            $result[$data->parent_key][$data->key] = $data;
        }

        return $result;
    }

    private function display_edit_form(int $id): void
    {
        $page = Ctx::$page;
        $page->set_title("Edit Navigation Link");

        $data = self::get_link_by_id($id);
        if ($data === null) {
            $page->set_redirect(make_link("nav_manager/list"));
            return;
        }

        $this->theme->display_edit_form($data);
    }

    public function onNavlinkUpdate(NavlinkUpdateEvent $event): void
    {
        $database = Ctx::$database;

        $data = $event->data;

        if ($data->id !== null) {
            $database->execute(
                "
                    UPDATE nav_manager
                    SET
                        parent_id = :parent_id,
                        description = :description,
                        url = :url,
                        sort_order = :sort_order,
                        enabled = :enabled,
                        modified = :modified
                    WHERE id = :id
                ",
                [
                    "id" => $data->id,
                    "parent_id" => $data->parent_id,
                    "description" => $data->description,
                    "url" => $data->url->getPage(),
                    "sort_order" => $data->sort_order,
                    "enabled" => $data->enabled,
                    "modified" => $data->modified,
                ]
            );
        } else {
            if ($data->is_default) {
                assert($data->key !== null);

                $existing = self::get_link_by_key($data->key, $data->parent_key);
                if ($existing !== null) {
                    $event->data->id = $existing->id;
                    return;
                }
            }

            $database->execute(
                "
                INSERT INTO nav_manager(
                    parent_id,
                    description,
                    url,
                    sort_order,
                    is_default,
                    key,
                    parent_key,
                    enabled,
                    modified
                ) VALUES(
                    :parent_id,
                    :description,
                    :url,
                    :sort_order,
                    :is_default,
                    :key,
                    :parent_key,
                    :enabled,
                    :modified
                )",
                [
                    "parent_id" => $data->parent_id,
                    "description" => $data->description,
                    "url" => $url = $data->url->getPage(),
                    "sort_order" => $data->sort_order,
                    "is_default" => $data->is_default,
                    "key" => $data->key,
                    "parent_key" => $data->parent_key,
                    "enabled" => $data->enabled,
                    "modified" => $data->modified,
                ]
            );

            $event->data->id = $database->get_last_insert_id("nav_manager_id_seq");
        }
    }

    private function restore_link(int $id): bool
    {
        $data = self::get_link_by_id($id);
        if ($data === null || $data->key === null) {
            return false;
        }

        $navlink = null;
        $event = send_event($data->parent_key === null ? new PageNavBuildingEvent()->ignorePermissions()->setSender(self::class) : new PageSubNavBuildingEvent($data->parent_key)->ignorePermissions()->setSender(self::class));
        foreach ($event->links as $link) {
            if ($link->key === $data->key) {
                $navlink = $link;
                break;
            }
        }

        if ($navlink === null) {
            return false;
        }

        $parent_id = null;
        if ($navlink->parent !== null) {
            $parent = self::get_link_by_key($navlink->parent);
            $parent_id = $parent?->id;
        }

        $data = LinkData::fromNavLink($navlink);
        $data->id = $id;
        $data->parent_id = $parent_id;
        $data->enabled = true;
        $data->modified = false;

        send_event(new NavlinkUpdateEvent($data));
        return true;
    }

    private function import_new_links(): void
    {
        $pnbe = send_event(new PageNavBuildingEvent()->ignorePermissions()->setSender(self::class));

        foreach ($pnbe->links as $link) {
            $data = LinkData::fromNavLink($link);
            $event = send_event(new NavlinkUpdateEvent($data));

            $parent_id = $event->data->id;

            if ($parent_id === null) {
                continue;
            }

            $psnbe = send_event(new PageSubNavBuildingEvent($link->key)->ignorePermissions()->setSender(self::class));

            foreach ($psnbe->links as $sublink) {
                $data = LinkData::fromNavLink($sublink);
                $data->parent_id = $parent_id;
                send_event(new NavlinkUpdateEvent($data));
            }
        }
    }
}
