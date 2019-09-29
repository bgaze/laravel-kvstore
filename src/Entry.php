<?php

namespace Bgaze\KvStore;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $type
 * @property mixed $value
 */
class Entry extends Model {

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kvstore';

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
     * @var array
     */
    protected $fillable = ['key', 'type', 'value'];

    /**
     * Override the casts array to force value casting based on type column.
     *
     * @return array
     */
    public function getCasts() {
        return empty($this->type) ? [] : ['value' => $this->type];
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value) {
        if ($this->isJsonCastable($key) && is_null($value)) {
            $value = [];
        }

        return parent::setAttribute($key, $value);
    }

}
