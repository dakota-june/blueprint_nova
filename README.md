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

`php artisan blueprint:build` writes `app/Nova/Invoice.php` and `app/Nova/Payment.php`.

## How ownership works

Generated members carry an `@generated blueprint_nova_resources` docblock tag.

| Member | Owner |
| --- | --- |
| `blueprintFields()`, `$model`, `$title`, `$search`, `$with`, `$group`, and every [resource option](#resource-options) you set (tagged) | The generator. Rewritten on every build. |
| `fields()` and everything untagged | You. Never touched after the file is created. |

New files get a `fields()` that spreads `...$this->blueprintFields($request)`, so you add your own fields beside the generated ones. To take over a generated member, delete its `@generated` tag. If the generator wants a name you already have untagged, it leaves yours alone and prints a notice.

When a resource file already exists (you wrote it, or an earlier build created it), the generator adds any missing tagged members to it: `$model`, `$title`, `$search`, `blueprintFields()` and the options you set. It never edits or removes anything untagged.

## `nova:` options

`model`, `group`, `title`, `search`, `fields`, `relations`, plus the [resource options](#resource-options) below. `nova: ~` with `auto_resources` enabled generates a resource for every non-pivot model; `Name: false` opts one out.

Field overrides (`fields: column: …`): a type string (`text textarea markdown trix code number currency boolean date datetime select badge email url password color country timezone hidden`) or a map with `omit as label hide readonly sortable rules map`. Both the column (`invoice_id`) and the relationship (`invoice`) name work for belongsTo; `as` is read from the column name only.

Column defaults: Nova's create form does not read database defaults, so `is_modifiable: boolean default:true` would save as false. A literal Blueprint `default:` is emitted as `->default(...)` on boolean, number, currency, text, textarea, email, url, color and select (enum values only) fields. Expressions such as `CURRENT_TIMESTAMP`, `null`, dates and numbers with leading zeros or exponents are skipped. The default applies to create forms only.

Supported relationships: `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`. Others produce a notice. Metrics, filters, actions, lenses, cards, dashboards and policies are planned and are rejected with a clear error rather than ignored.

When a related model has no Nova resource, a belongsTo falls back to a plain field and collection relations are skipped; both print a notice.

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
