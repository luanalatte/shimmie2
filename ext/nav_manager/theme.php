<?php

declare(strict_types=1);

namespace Shimmie2;

use MicroHTML\HTMLElement;

use function MicroHTML\{INPUT, LABEL, OPTION, SELECT, TABLE, TD, TR, emptyHTML};

class NavManagerTheme extends Themelet
{
    public function display_table(HTMLElement $table, HTMLElement $paginator): void
    {
        $page = Ctx::$page;
        $page->set_title("Navigation Manager");

        $page->add_block(new Block("Actions", emptyHTML(
            SHM_SIMPLE_FORM(
                make_link("nav_manager/import"),
                SHM_SUBMIT("Import new links")
            )
        ), "left"));

        $page->add_block(new Block("Navigation Manager", emptyHTML(
            $table,
            $paginator
        )));
    }

    public function display_edit_form(array $row)
    {
        $page = Ctx::$page;
        $is_default = (bool) $row["is_default"];

        $sideblock = emptyHTML();

        if ($is_default) {
            $sideblock->appendChild(
                SHM_SIMPLE_FORM(
                    make_link("nav_manager/restore"),
                    INPUT(["type" => "hidden", "name" => "id", "value" => $row["id"]]),
                    SHM_SUBMIT("Restore link defaults")
                )
            );
        }

        $page->add_block(new Block("Actions", $sideblock, "left"));

        $table = TABLE(["class" => "form"]);

        $parent_select = SELECT(
            ["id" => "parent_id", "name" => "parent_id"],
            OPTION(["value" => "", ... $row["parent_id"] === null ? ["selected" => "selected"] : []], "None")
        );

        foreach ($this->get_all_parents() as $parent) {
            if (($parent["id"]) === $row["id"]) {
                continue;
            }

            $parent_select->appendChild(
                OPTION([
                    "value" => $parent["id"],
                    ... $parent["id"] === $row["parent_id"] ? ["selected" => "selected"] : []
                ], $parent["description"])
            );
        }

        $table->appendChild(
            TR(
                TD(LABEL(["for" => "parent_id"], "Parent")),
                TD($parent_select),
            ),
            TR(
                TD(LABEL(["for" => "description"], "Description")),
                TD(INPUT(["type" => "text", "id" => "description", "name" => "description", "value" => $row["description"]]))
            ),
            TR(
                TD(LABEL(["for" => "url"], "URL")),
                TD(INPUT(["type" => "text", "id" => "url", "name" => "url", "value" => $row["url"]]))
            ),
            TR(
                TD(LABEL(["for" => "sort_order"], "Order")),
                TD(INPUT(["type" => "number", "id" => "sort_order", "name" => "sort_order", "value" => $row["sort_order"]]))
            ),
            TR(
                TD(LABEL(["for" => "enabled"], "Enabled")),
                TD(INPUT(["type" => "checkbox", "id" => "enabled", "name" => "enabled", ... $row["enabled"] ? ["checked" => "true"] : []]))
            ),
            TR(
                TD(
                    ["colspan" => 2],
                    SHM_SUBMIT("Save"),
                )
            )
        );

        $html = emptyHTML(
            SHM_SIMPLE_FORM(
                make_link("nav_manager/save"),
                INPUT(["type" => "hidden", "name" => "id", "value" => $row["id"]]),
                $table,
            )
        );

        $page->add_block(new Block("Edit Navigation Link", $html));
    }

    protected function get_all_parents(): array
    {
        return Ctx::$database->get_all("SELECT id, description FROM nav_manager WHERE parent_id IS NULL ORDER BY sort_order");
    }
}
