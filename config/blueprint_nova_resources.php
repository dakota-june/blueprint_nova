<?php

declare(strict_types=1);

return [
    /*
    | Namespace (relative to blueprint.namespace) of the generated resources: App\Nova by default.
    */
    'namespace' => 'Nova',

    /*
    | Generate a resource for every model in the draft that is not listed under `nova:`.
    | Opt a model out with `Name: false`. Pivot models are skipped.
    */
    'auto_resources' => false,

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
    | $group for every resource that does not set `group:`. null leaves Nova's default.
    */
    'default_group' => null,

    /*
    | Rules added to generated password fields (on top of "required" when creating, "nullable" when updating).
    */
    'password_rules' => ['string', 'min:8'],

    /*
    | Text columns whose name matches get a more specific field. fnmatch() patterns.
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
