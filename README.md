# dakotajune/blueprint_nova_resources

Generate [Laravel Nova](https://nova.laravel.com) resources from your [Blueprint](https://blueprint.laravelshift.com) `draft.yaml`.

```yaml
models:
    Invoice:
        number: string:20 unique
        status: enum:draft,issued,paid
        total: decimal:8,2
        relationships:
            hasMany: Payment
    Payment:
        invoice_id: id foreign
        amount: decimal:8,2

nova:
    Invoice:
    Payment:
        group: Billing
        fields:
            amount: { label: Paid, hide: [index] }
```

Requires Laravel 13 and PHP 8.3+.

`php artisan blueprint:build` writes `app/Nova/Invoice.php` and `app/Nova/Payment.php`.

Don't want to list every resource? Set `'auto_resources' => true` in the config and leave the `nova:` section out: every model in the draft gets a resource. See [Scaffolding from models](#scaffolding-from-models).

## How ownership works

Generated members carry an `@generated blueprint_nova_resources` docblock tag.

| Member | Owner |
| --- | --- |
| `blueprintFields()`, `$model`, `$title`, `$search`, `$with`, `$group`, and every [resource option](#resource-options) you set (tagged) | The generator. Rewritten on every build. |
| `fields()` and everything untagged | You. Never touched after the file is created. |

New files get a `fields()` that spreads `...$this->blueprintFields($request)`, so you add your own fields beside the generated ones. To take over a generated member, delete its `@generated` tag. If the generator wants a name you already have untagged, it leaves yours alone and prints a notice.

When a resource file already exists (you wrote it, or an earlier build created it), the generator adds any missing tagged members to it: `$model`, `$title`, `$search`, `blueprintFields()` and the options you set. It never edits or removes anything untagged. New properties go after the properties the class already has, so a `use SomeTrait;` or constant at the top stays where it is.

Two things the build tells you about an existing file:

- An untagged member the generator wanted to write is reported, unless it is already exactly what would have been generated (same code, ignoring comments, spacing and trailing commas), in which case there is nothing to say.
- If its `fields()` never calls `blueprintFields()`, the fields generated from the draft are written but never shown. The build says so; add `...$this->blueprintFields($request)` to the array `fields()` returns.

## `nova:` options

`model`, `group`, `title`, `search`, `fields`, `relations`, plus the [resource options](#resource-options) below.

Field overrides (`fields: column: …`): a type string (`text textarea markdown trix code number currency boolean date datetime select badge email url password color country timezone hidden`) or a map with `omit as label hide readonly sortable rules map`. Both the column (`invoice_id`) and the relationship (`invoice`) name work for belongsTo, but `as` is only accepted on the column: a relationship's field type is fixed, so `invoice: { as: text }` is an error that points you at `invoice_id`. Overrides are matched by name, so a model with a column and a relationship called the same thing (a `notes` text column and a `notes` hasMany) is an error as soon as you override that name. Rename one of them.

Two shortcuts live on the model's columns, where the column is already described. Blueprint ignores both, so they cost nothing in migrations or models, and both work through YAML anchors:

```yaml
models:
  ContactType:
    description: text nullable novaField:markdown     # same as `fields: description: markdown` under nova:
    legacy_code: string nullable novaField:false      # same as `fields: legacy_code: false`: leave it out
```

Two more shortcuts set what the field says to the user:

```yaml
    description: text nullable novaField:markdown novaLabel:"About this type" novaHelp:"Shown under the field"
```

`novaLabel` is the label (`fields: description: { label: ... }`) and `novaHelp` is Nova's `->help()` text (`{ help: ... }`, which `fields:` accepts too). Put several words in quotes so they stay one value; plain YAML also needs the whole column definition in quotes if the text contains `: `. For a belongsTo, write them on the foreign key column (`invoice_id: id foreign novaLabel:"Parent invoice"`). Quotes in the text are escaped for you. A value with no text is an error.

`novaCreate` puts Nova's "create a new related record" button on a belongsTo field (`->showCreateRelationButton()`). It goes on the foreign key column, like `novaLabel`:

```yaml
  Contact:
    contact_type_id: id foreign novaCreate          # same as novaCreate:true
```

The same thing under `nova:`, by relationship name or by column: `fields: contactType: { create: true }`. To put the button on every BelongsTo, set `'create_relation_button' => true` in the config; a column then opts out with `novaCreate:false`. Nova shows no button unless asked, so nothing is written for false. Only belongsTo fields have this button: `create` on any other column, or on a hasMany or belongsToMany, stops the build with an error. When a column shortcut and a relationship-name override both exist, they are combined and the relationship's own values win.

`novaField:<type>` takes any field type a draft's `fields:` takes (case does not matter). What the draft writes under `nova:` for the same column beats the shortcuts one property at a time, so `fields: description: { label: About }` plus `novaField:markdown` gives a Markdown field labelled About, and its `novaHelp` is kept. An unknown type stops the build and lists the valid ones. The type registry's rules apply to every column; the shortcut is for one.

The default `$search` is the primary key plus the first `search_limit` text columns. Columns matching `search_exclude` in the config (secrets, `color`, `uuid`, `locale`, ...) are skipped. You can steer it from the model itself by marking columns:

```yaml
models:
  ContactPhone:
    country: string:2 searchable:false      # never in $search
    number: string searchable               # same as searchable:true
    extension: string nullable searchable:true
```

`searchable` (or `searchable:true`) always puts the column in `$search`, whatever its type, the exclusion list or `search_limit`. `searchable:false` keeps it out. Unmarked columns follow the rules above. Blueprint ignores the mark, so it costs nothing in migrations or models, and it works through YAML anchors. A draft's own `search:` under `nova:` beats the marks and must name real columns (an empty entry such as `search: [~]` is an error). This is not the resource-level `searchable` option below, which turns the index search box on or off.

### Which field a column becomes

A [registry](#the-field-type-registry) decides it: a string column is a `Text`, a text column a `Textarea`, a column called `email` an `Email`, and so on. Change those choices for every resource at once in the config instead of overriding column by column in each draft.

Validation: each field gets rules that follow from the column, so a value the database would refuse is refused by the form first. `required` unless the column is nullable or has a default, then by type:

| Column | Rules |
| --- | --- |
| `string:N`, `char:N` | `max:N` (255 with no length) |
| `integer` family | `integer`, and the range the type holds: `between:-128,127` for `tinyInteger`, `between:0,255` unsigned, `between:-32768,32767` for `smallInteger`, and so on up to 32 bits. 64-bit columns only get `min:0` when unsigned. `year` is `between:1901,2155` |
| `decimal:P,S` | `numeric`, `decimal:0,S` and `between:-M,M` (`between:0,M` unsigned) where M is the largest value the column holds: `decimal:8,2` is `between:-999999.99,999999.99`. With no precision Laravel makes it `decimal(8, 2)` and so does this package. Scale 0 gives `integer` |
| `float`, `double` | `numeric`, and `min:0` when unsigned |
| `json` | `json` |
| `date`, `dateTime`, `timestamp` | `date` |
| `uuid`, `ulid`, `ipAddress`, `macAddress`, `time` | `uuid`, `ulid`, `ip`, `mac_address`, `date_format:H:i:s` |
| `enum` | `in:...` |
| email and url fields | `email` or `url`, plus `max:N` |
| `unique` columns | `unique:` rules for create and update |

Set `'type_rules' => false` in the config to keep only the basics (`required`, `max`, `email`, `url`, `integer`/`numeric`, `min:0` on unsigned integers, `in:` and `unique:`). `rules:` on a field in the draft replaces all derived rules, whichever way the option is set.

Column defaults: Nova's create form does not read database defaults, so `is_modifiable: boolean default:true` would save as false. A literal Blueprint `default:` is emitted as `->default(...)` on boolean, number, currency, text, textarea, email, url, color and select (enum values only) fields. Expressions such as `CURRENT_TIMESTAMP`, `null`, dates and numbers with leading zeros or exponents are skipped. The default applies to create forms only.

Supported relationships: `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`. Others produce a notice. Metrics, filters, actions, lenses, cards, dashboards and policies are planned and are rejected with a clear error rather than ignored.

When a related model has no Nova resource, a belongsTo falls back to a plain field and collection relations are skipped; both print a notice.

## Scaffolding from models

```php
// config/blueprint_nova_resources.php
'auto_resources' => true,
'auto_resources_except' => ['PasswordReset', 'Admin\\AuditLog'],
```

With `auto_resources` on, every model in the draft gets a resource, whether or not there is a `nova:` section. Pivot models are skipped, and so are the models in `auto_resources_except`.

A draft can still say more about any model. List it under `nova:` and it takes those options (`Payment: { group: Billing }`); a model in `auto_resources_except` that the draft lists is generated anyway; `Name: false` opts one out for this draft; `nova: false` switches the package off for it.

## The field type registry

One table says which Nova field a column becomes. Rules are checked most specific first:

1. **Name**: a text or string column whose name matches a pattern. `name_hints` in the config; built in: `email`, `*_email` become Email, `password` becomes Password, `url`, `*_url`, `website` become URL.
2. **Type**: `field_types` in the config, keyed by a Blueprint type (`longText`, `char`, `uuid`) or its category. The type wins over its category.
3. **Built in**: integer and decimal become Number, string becomes Text, text becomes Textarea, json becomes Code, boolean becomes Boolean, date becomes Date, datetime becomes DateTime, enum becomes Select.

```php
'field_types' => [
    'text' => 'markdown',      // every text column is a Markdown field
    'uuid' => 'text',
],
'name_hints' => [
    'markdown' => ['description', 'body'],
    'url' => ['url', '*_url', 'website'],
],
```

Categories are `string`, `text`, `integer`, `decimal`, `json`, `boolean`, `date`, `datetime` and `enum`. Values are the field types a draft's `fields:` accepts.

The same rules can be added from code, for instance in a service provider. Rules added in code beat the config, and a later rule beats an earlier one:

```php
app(\Dakotajune\BlueprintNovaResources\Fields\FieldTypeRegistry::class)
    ->type('text', 'markdown')
    ->name(['description', '*_notes'], 'markdown');
```

A draft's own `fields:` always wins over the registry. The primary key, housekeeping columns (timestamps, `remember_token`, soft deletes) and foreign keys are never remapped. A rule naming a field type the package does not know is an error that says which rule it came from. A field type that needs more than the column gives it still fails with its own message, for example `select` on a column that is not an enum.

## Resource options

Presentation settings for the resource itself. One rule covers all of them: **a member is written only when your draft states a value that differs from Nova's default.** Leave an option out, or set it to Nova's default, and nothing is generated; remove it later and the generated member is deleted on the next build.

```yaml
nova:
  ContactType:
    group: Contacts
    navigation: false          # hide from the sidebar
    label: Contact Types
    singular: Contact Type
    globalSearch: { results: 10, link: edit }
    perPage: [10, 25]
    fields:
      is_modifiable: { label: Modifiable }
      is_deletable: { label: Deletable }
```

| Key | Writes | Value | Written when |
| --- | --- | --- | --- |
| `navigation` | `$displayInNavigation` | `true` / `false` | `false` |
| `label` | `label()` | text | set |
| `singular` | `singularLabel()` | text | set |
| `searchable` | `$searchable` (index search box) | `true` / `false` | `false` |
| `globalSearch` | see below | `false`, or a map | see below |
| `trafficCop` | `$trafficCop` | `true` / `false` | `false` |
| `showColumnBorders` | `$showColumnBorders` | `true` / `false` | `true` |
| `tableStyle` | `$tableStyle` | `default` / `tight` | `tight` |
| `clickAction` | `$clickAction` | `detail` `edit` `select` `preview` `ignore` | not `detail` |
| `debounce` | `$debounce` | number, 0 or more | not `0.5` |
| `perPage` | `$perPageOptions` | a number or a list of numbers | not `[25, 50, 100]` |
| `perPageViaRelationship` | `$perPageViaRelationshipOptions` | a number or a list of numbers | set |
| `relatableSearchResults` | `$relatableSearchResults` | whole number | set |
| `scoutSearchResults` | `$scoutSearchResults` | whole number | not `200` |

`globalSearch: false` is shorthand for `enabled: false`. Inside the map: `enabled` (`$globallySearchable`, written when `false`), `results` (`$globalSearchResults`, written when not `5`) and `link` (`$globalSearchLink`, written when not `detail`).

Things to know:

- `label` and `singular` are written as `public static` methods returning a plain string. Nova derives `singularLabel()` from `label()`, so set `singular` only when that guess is wrong. To translate a label, delete its `@generated` tag and write the method yourself.
- Labels do not change the resource's URL; Nova builds that from the class name.
- A resource hidden with `navigation: false` is still a normal resource: it is registered, and other resources still link to it with `BelongsTo` and `HasMany`.
- Defaults and member names follow Nova 5. On Nova 4 a few members (`$clickAction`, `$tableStyle`, `$showColumnBorders`, `$debounce`) do not exist and have no effect.
- Because only differences are written, the draft cannot force a value back to Nova's default when your own base `Resource` class changes it. Take over the member by hand instead.

## Erase

`blueprint:erase` would delete every created file. Just before it runs, this package strips its generated members from each resource and, if anything of yours remains, removes that file from `.blueprint`'s `created` list (a `.blueprint.bak-<timestamp>` copy is written first). Untouched resources are deleted as normal.

## Extending

For a different field type for a column, add a rule to the [registry](#the-field-type-registry). To take over mapping entirely, register a mapper:

```php
app(\Dakotajune\BlueprintNovaResources\Fields\FieldMapperRegistry::class)->register(new MyMapper(), priority: 10);
```

A mapper returns `null` to defer to the next one, `[]` to omit the column, or `FieldDefinition`s to use.

## Config / stubs

```bash
php artisan vendor:publish --tag=blueprint-nova-resources-config
php artisan vendor:publish --tag=blueprint-nova-resources-stubs
```

## Development

```bash
composer install
vendor/bin/pest
```
