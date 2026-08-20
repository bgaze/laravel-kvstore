<?php

declare(strict_types=1);

namespace Bgaze\KvStore;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * A single store entry: a key, the cast to apply to its value, and the value.
 *
 * @property string $key
 * @property string|null $type
 * @property mixed $value
 */
class Entry extends Model
{
    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'key';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['key', 'type', 'value'];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        $table = Config::get('kvstore.table');

        return is_string($table) && $table !== '' ? $table : 'kvstore';
    }

    /**
     * Get the casts array.
     *
     * The cast of an entry is driven by its own type column, so it is resolved
     * from the stored attribute rather than declared statically. The raw
     * attribute is read directly: going through the accessor would ask for the
     * casts of the very column being used to compute them.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        $type = $this->attributes['type'] ?? null;

        return is_string($type) && $type !== '' ? ['value' => $type] : [];
    }

    /**
     * Set a given attribute on the model.
     *
     * A null written on a JSON-backed cast is stored as an empty array, so that
     * such an entry always reads back as the collection type it declares
     * instead of handing back a null the caller has to guard.
     *
     * The parameter cannot be narrowed to a string: the parent leaves it
     * untyped, and PHP forbids restricting an inherited parameter type.
     */
    public function setAttribute(mixed $key, mixed $value): mixed
    {
        if ($value === null && $this->isJsonCastable($key)) {
            $value = [];
        }

        return parent::setAttribute($key, $value);
    }
}
