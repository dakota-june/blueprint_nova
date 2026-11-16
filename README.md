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
| `blueprintFields()`, `$model`, `$title`, `$search`, `$with`, `$group` (tagged) | The generator. Rewritten on every build. |
| `fields()` and everything untagged | You. Never touched after the file is created. |

New files get a `fields()` that spreads `...$this->blueprintFields($request)`, so you add your own fields beside the generated ones. To take over a generated member, delete its `@generated` tag. If the generator wants a name you already have untagged, it leaves yours alone and prints a notice.

## `nova:` options

`model`, `group`, `title`, `search`, `fields`, `relations`. `nova: ~` with `auto_resources` enabled generates a resource for every non-pivot model; `Name: false` opts one out.

Field overrides (`fields: column: …`): a type string (`text textarea markdown trix code number currency boolean date datetime select badge email url password color country timezone hidden`) or a map with `omit as label hide readonly sortable rules map`. Both the column (`invoice_id`) and the relationship (`invoice`) name work for belongsTo.

Column defaults: Nova's create form does not read database defaults, so `is_modifiable: boolean default:true` would save as false. A literal Blueprint `default:` is emitted as `->default(...)` on boolean, number, currency, text, textarea, email, url, color and select (enum values only) fields. Expressions such as `CURRENT_TIMESTAMP`, `null`, dates and numbers with leading zeros or exponents are skipped. The default applies to create forms only.

Supported relationships: `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`. Others produce a notice. Metrics, filters, actions, lenses, cards, dashboards and policies are planned and are rejected with a clear error rather than ignored.

When a related model has no Nova resource, a belongsTo falls back to a plain field and collection relations are skipped; both print a notice.

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
