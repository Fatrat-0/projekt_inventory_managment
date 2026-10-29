<?php

namespace App\Support;

use Illuminate\Support\Collection;

class CategoryTree
{
    private Collection $nodes;

    public function __construct(Collection $categories) { $this->nodes = $categories->keyBy('category_id'); }

    public function path(int $id): string
    {
        $parts = []; $visited = [];
        while ($id && isset($this->nodes[$id]) && !isset($visited[$id])) {
            $visited[$id] = true;
            $node = $this->nodes[$id];
            array_unshift($parts, $node->category_name);
            $id = (int) $node->parent_id;
        }
        return implode(' / ', $parts);
    }

    public function branchIds(int $id): array
    {
        $ids = [$id]; $visited = [$id => true];
        $children = $this->nodes->groupBy('parent_id');
        for ($i = 0; $i < count($ids); $i++) foreach ($children->get($ids[$i], collect()) as $child) {
            if (isset($visited[$child->category_id])) continue;
            $ids[] = $child->category_id; $visited[$child->category_id] = true;
        }
        return $ids;
    }

    public function wouldCreateCycle(?int $id, ?int $parentId): bool
    {
        $visited = [];
        while ($parentId && isset($this->nodes[$parentId])) {
            if ($parentId === $id || isset($visited[$parentId])) return true;
            $visited[$parentId] = true;
            $parentId = (int) $this->nodes[$parentId]->parent_id;
        }
        return false;
    }
}
