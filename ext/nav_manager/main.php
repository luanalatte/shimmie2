<?php

declare(strict_types=1);

namespace Shimmie2;

use MicroCRUD\{ActionColumn, Column, IntegerColumn, Table, TextColumn};
use MicroHTML\HTMLElement;

use function MicroHTML\{INPUT, OPTION, SELECT};

final class ButtonColumn extends ActionColumn
{
    public function display(array $row): HTMLElement|string
    {
        if ($this->table->update_url) {
            return SHM_FORM(
                make_link($this->table->update_url),
                method: "GET",
                children: [
                    INPUT(["type" => "hidden", "name" => "$this->name", "value" => $row[$this->name]]),
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

    public function read_input(array $inputs): HTMLElement|string
    {
        $value = @$inputs["r_{$this->name}"];
        return SELECT(
            ["name" => "r_$this->name"],
            OPTION(["value" => "", ...$value === "" ? ["selected" => "selected"] : []], "Any"),
            ... $this->inverse ? [
                OPTION(["value" => "0", ...$value === "0" ? ["selected" => "selected"] : []], "Yes"),
                OPTION(["value" => "1", ...$value === "1" ? ["selected" => "selected"] : []], "No"),
            ] : [
                OPTION(["value" => "0", ...$value === "0" ? ["selected" => "selected"] : []], "No"),
                OPTION(["value" => "1", ...$value === "1" ? ["selected" => "selected"] : []], "Yes"),
            ]
        );
    }

    public function display(array $row): HTMLElement|string
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
            if (Ctx::$user->can(NavManagerPermission::MANAGE_NAVLINKS)) {
                $t->update_url = "nav_manager/edit";
            }
            $this->theme->display_table($t->table($t->query()), $t->paginator());
            return;
        }

        if ($event->page_matches("nav_manager/edit")) {
            $id = (int) $event->GET->req("id");
            $this->show_edit_form($id);
            return;
        }

        if ($event->page_matches("nav_manager/save", "POST")) {
            $row = $event->POST->toArray();
            $this->save_changes($row);
            return;
        }

        if ($event->page_matches("nav_manager/restore", "POST")) {
            $id = (int) $event->POST->req("id");
            $this->restore_link($id);
            $page->flash("Link data restored.");
            $page->set_redirect(make_link("nav_manager/edit", ["id" => $id]));
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

    private function modify_links(PageNavBuildingEvent|PageSubNavBuildingEvent $event)
    {
        // if ($event->all) {
        //     return;
        // }

        $parent = $event instanceof PageSubNavBuildingEvent ? $event->parent : null;

        $data = $this->get_modified_links($parent);

        $links = [];
        foreach ($event->links as $link) {
            if (isset($data[$parent]) && isset($data[$parent][$link->key])) {
                $link_data = $data[$parent][$link->key];

                if (!$link_data["enabled"] || $link_data["current_parent"] !== $parent) {
                    if ($event->active_link !== null && $link->key === $event->active_link->key) {
                        $event->active_link = null;
                    }
                    continue;
                }

                $link->link = make_link($link_data["url"]);
                $link->description = $link_data["description"];
                $link->order = $link_data["order"];

                unset($data[$parent][$link->key]);
            }

            $links[] = $link;
        }

        $event->links = $links;

        foreach ($data as $new_links) {
            foreach ($new_links as $row) {
                if (!$row["enabled"] || $row["current_parent"] !== $parent) {
                    continue;
                }

                if ($event instanceof PageNavBuildingEvent) {
                    $event->add_nav_link(make_link($row["url"]), $row["description"], key: $row["key"], order: $row["sort_order"]);
                } elseif ($event instanceof PageSubNavBuildingEvent) {
                    $event->add_nav_link(make_link($row["url"]), $row["description"], order: $row["sort_order"], key: $row["key"]);
                }
            }
        }
    }

    public function insert_link(NavLink $link, ?int $parent_id = null, bool $modified = false, bool $enabled = true, bool $is_default = true): bool
    {
        if ($is_default && $this->record_exists_by_key($link->key, $link->parent)) {
            return false;
        }

        $database = Ctx::$database;

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
                "parent_id" => $parent_id,
                "description" => $link->description,
                "url" => $url = $link->link->getPage(),
                "sort_order" => $link->order,
                "is_default" => (int) $is_default,
                "key" => $link->key,
                "parent_key" => $link->parent,
                "enabled" => (int) $enabled,
                "modified" => (int) $modified,
            ]
        );

        return true;
    }

    public function save_link(int $id, NavLink $link, ?int $parent_id = null, bool $enabled = true, bool $modified = true): bool
    {
        $database = Ctx::$database;

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
                "id" => $id,
                "parent_id" => $parent_id,
                "description" => $link->description,
                "url" => $link->link->getPage(),
                "sort_order" => $link->order,
                "enabled" => (int) $enabled,
                "modified" => (int) $modified,
            ]
        );

        return true;
    }

    private function get_link_by_id(int $id): ?array
    {
        return Ctx::$database->get_row("SELECT * FROM nav_manager WHERE id = :id", ["id" => $id]);
    }

    private function get_link_by_key(string $key): ?array
    {
        return Ctx::$database->get_row("SELECT * FROM nav_manager WHERE key = :key", ["key" => $key]);
    }

    private function get_modified_links(?string $parent = null)
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

        $items = Ctx::$database->get_all($query, $args);

        $result = [];
        foreach ($items as $link) {
            if (!isset($result[$link["parent_key"]])) {
                $result[$link["parent_key"]] = [];
            }

            $result[$link["parent_key"]][$link["key"]] = $link;
        }

        return $result;
    }

    private function show_edit_form(int $id)
    {
        Ctx::$page->set_title("Edit Navigation Link");
        $row = $this->get_link_by_id($id);
        $this->theme->display_edit_form($row ?? []);
    }

    public function save_changes(array $row): void
    {
        $page = Ctx::$page;

        $id = (int) $row["id"];
        $parent_id = ((int) $row["parent_id"]) ?: null;
        $description = $row["description"];
        $url = mb_ltrim($row["url"], "/");
        $order = (int) $row["sort_order"];
        $enabled = isset($row["enabled"]);

        // if (!$id) {
        //     if ($this->insert_link(
        //         new NavLink(make_link($url), $description, key: $key, order: $order, parent: $parent),
        //         modified: true,
        //         enabled: $enabled,
        //         is_default: false
        //     )) {
        //         $page->flash("Changes saved.");
        //         $page->set_redirect(make_link("nav_manager/list"));
        //     }
        //     return;
        // }

        if (
            $this->save_link(
                $id,
                new NavLink(make_link($url), $description, "", order: $order),
                $parent_id,
                $enabled
            )
        ) {
            $page->flash("Changes saved.");
            $page->set_redirect(make_link("nav_manager/list"));
            return;
        }
    }

    private function restore_link(int $id): bool
    {
        $data = $this->get_link_by_id($id);

        $link = $this->find_link_by_key($data["key"], $data["parent_key"]);
        if ($link !== null) {
            $parent_data = [];
            if ($link->parent) {
                $parent_data = $this->get_link_by_key($link->parent);
            }

            return $this->save_link(
                $id,
                $link,
                $parent_data["id"] ?? null,
                modified: false,
                enabled: true
            );
        }

        return false;
    }

    private function import_new_links(): void
    {
        $pnbe = send_event(new PageNavBuildingEvent());

        foreach ($pnbe->links as $link) {
            $parent_id = null;
            if ($this->insert_link($link)) {
                $parent_id = Ctx::$database->get_last_insert_id("nav_manager_id_seq");
            }

            if ($parent_id === null) {
                $parent_id = $this->get_link_by_key($link->key)["id"] ?? null;
            }

            $psnbe = send_event(new PageSubNavBuildingEvent($link->key));

            foreach ($psnbe->links as $sublink) {
                $this->insert_link($sublink, $parent_id);
            }
        }
    }

    /**
     * Finds a NavLink by the given key, using the original parent and not the one set in the DB.
     * @param string $key
     * @param ?string $parent_key
     * @return NavLink|null
     */
    private function find_link_by_key(string $key, ?string $parent_key = null): ?NavLink
    {
        $event = send_event($parent_key === null ? new PageNavBuildingEvent() : new PageSubNavBuildingEvent($parent_key));

        foreach ($event->links as $link) {
            if ($link->key === $key) {
                return $link;
            }
        }

        return null;
    }

    private function record_exists_by_key(string $key, ?string $parent = null): bool
    {
        if ($parent === null) {
            if (Ctx::$database->get_one("SELECT 1 FROM nav_manager WHERE key = :key", ["key" => $key])) {
                return true;
            }
        } else {
            if (Ctx::$database->get_one("SELECT 1 FROM nav_manager WHERE key = :key AND parent_key = :parent_key", ["key" => $key, "parent_key" => $parent])) {
                return true;
            }
        }

        return false;
    }
}
