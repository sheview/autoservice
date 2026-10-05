<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartCategory;

/**
 * Creates or updates a part category. Its track_serial is only what new parts of it start
 * with: the parts already in it keep theirs.
 */
class SavePartCategory
{
    /**
     * @param  array{name: string, track_serial?: bool}  $data
     */
    public function handle(?PartCategory $category, array $data): PartCategory
    {
        $category ??= new PartCategory;
        $category->fill(['name' => trim($data['name']), 'track_serial' => (bool) ($data['track_serial'] ?? false)])->save();

        return $category;
    }
}
