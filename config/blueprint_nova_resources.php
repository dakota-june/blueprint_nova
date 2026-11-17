<?php

declare(strict_types=1);

return [
    /*
    | Namespace (relative to blueprint.namespace) of the generated resources: App\Nova by default.
    */
    'namespace' => 'Nova',

    /*
    | Generate a resource for every model in the draft, without listing them under `nova:` (leave the section out
    | altogether). Models you do list still take their own options; opt one out with `Name: false`, or for good with
    | `auto_resources_except`. Pivot models are skipped.
    */
    'auto_resources' => false,

    /*
    | Models that `auto_resources` never scaffolds, e.g. ['PasswordReset', 'Admin\\AuditLog']. Listing one under
    | `nova:` in a draft still generates it.
    */
    'auto_resources_except' => [],

    /*
    | Class the generated resources extend. null = App\Nova\Resource when that file exists
    | (what Nova's own installer creates), otherwise Laravel\Nova\Resource.
    */
    'base_resource' => null,

    /*
    | created_at / updated_at: 'detail' (detail page only), 'index' (index and detail, never on forms) or false.
    */
    'timestamps' => 'detail',

    /*
    | Make scalar fields (text, number, boolean, date, select, ...) sortable on the index page.
    */
    'sortable' => true,

    /*
    | Columns tried, in order, for the resource's $title when the draft does not set `title:`.
    */
    'title_candidates' => ['name', 'title', 'label', 'subject', 'number', 'code', 'slug', 'email'],

    /*
    | How many text columns are put into $search when the draft does not set `search:`.
    */
    'search_limit' => 5,

    /*
    | Text columns that are never put into the default $search: secrets, and columns that hold a format or an
    | identifier rather than words. fnmatch() patterns, case-insensitive. A draft's own `search:` ignores this list.
    */
    'search_exclude' => [
        '*password*', '*secret*', '*token*', '*hash*',
        '*color', '*colour', 'uuid', 'ulid', 'ip_address', 'mac_address', 'locale', 'timezone', 'currency',
    ],

    /*
    | Validation rules that follow from a column's data type, on top of required, max, email and url: integers kept
    | inside what tinyInteger ... integer can hold, decimals inside their places and size (decimal(8,2) takes two
    | decimal places and nothing at or above 1,000,000), json, date, uuid, ulid, ip and mac addresses. false keeps only
    | the basics. A draft's own `rules:` on a field replaces all of them either way.
    */
    'type_rules' => true,

    /*
    | Put Nova's "create a new related record" button (->showCreateRelationButton()) on every BelongsTo field.
    | Nova's own default is off. A column opts in or out with `novaCreate` / `novaCreate:false`, a draft with
    | `fields: contactType: { create: true }`. Only BelongsTo fields have this button.
    */
    'create_relation_button' => false,

    /*
    | $group for every resource that does not set `group:`. null leaves Nova's default.
    */
    'default_group' => null,

    /*
    | Rules added to generated password fields (on top of "required" when creating, "nullable" when updating).
    */
    'password_rules' => ['string', 'min:8'],

    /*
    | Which Nova field a column becomes. A column is looked up by name (`name_hints`), then by its Blueprint type or
    | category (`field_types`), then the built-in defaults: integer/decimal => number, string => text,
    | text => textarea, json => code, boolean => boolean, date => date, datetime => datetime, enum => select.
    | A draft's own `fields:` always wins. Values: text textarea markdown trix code number currency boolean date
    | datetime select badge email url password color country timezone hidden.
    |
    | Keys of field_types are a Blueprint type ('longText', 'char', 'uuid', ...) or one of the categories
    | string, text, integer, decimal, json, boolean, date, datetime, enum; the type wins over its category.
    |
    |     'field_types' => ['text' => 'markdown', 'uuid' => 'text'],
    |
    | Code can add rules too: app(FieldTypeRegistry::class)->type('text', 'markdown')->name('*_url', 'url').
    */
    'field_types' => [],

    /*
    | Text and string columns whose name matches get a more specific field. Field type => fnmatch() patterns.
    */
    'name_hints' => [
        'email' => ['email', '*_email'],
        'password' => ['password'],
        'url' => ['url', '*_url', 'website'],
    ],

    /*
    | Your Nova service provider, checked after a build so the new resources are not forgotten.
    | Nothing is reported when the file does not exist or already calls Nova::resourcesIn().
    */
    'provider' => 'app/Providers/NovaServiceProvider.php',

    /*
    | Records what was generated so `blueprint:erase` can undo it safely. Relative to the project root.
    | Commit it or ignore it, same as `.blueprint`.
    */
    'manifest' => '.blueprint_nova_resources',
];
