<?php

namespace App\Support;

use Countable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traversable;
use IteratorAggregate;

/**
 * Tableau de 10 lignes par page, trié sur toutes ses lignes et pas seulement sur la page affichée.
 * Sans tri demandé, l'ordre habituel du contrôleur est conservé. Seules les colonnes déclarées
 * sont triables. Chaque tableau a ses paramètres : plusieurs peuvent cohabiter sur une page.
 */
class SortedTable implements Countable, IteratorAggregate
{
    public const PER_PAGE = 10;

    private function __construct(public readonly string $name, private LengthAwarePaginator $rows)
    {
    }

    public static function query($query, string $name, array $columns, bool $tieBreak = true): self
    {
        if ($sort = self::requested($name, $columns)) {
            $query->reorder()->orderBy($columns[$sort[0]], $sort[1]);

            // Les lignes à égalité gardent le même ordre d'une page à l'autre.
            if ($tieBreak) {
                $query->orderBy($query->getModel()->getQualifiedKeyName());
            }
        }

        return new self($name, $query->paginate(self::PER_PAGE, ['*'], $name.'_page')->withQueryString()->fragment('t-'.$name));
    }

    // Pour les lignes déjà calculées en PHP : chaque colonne donne la valeur à comparer.
    public static function collection(Collection $rows, string $name, array $columns): self
    {
        if ($sort = self::requested($name, $columns)) {
            $rows = $rows->sortBy($columns[$sort[0]], SORT_REGULAR, $sort[1] === 'desc');
        }

        $pageName = $name.'_page';
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);

        $paginator = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page, [
            'path' => request()->url(),
            'pageName' => $pageName,
        ]);

        return new self($name, $paginator->withQueryString()->fragment('t-'.$name));
    }

    // Colonne d'une table liée, par exemple le nom du client d'un relevé.
    public static function related(string $table, string $column, string $key, string $foreignKey)
    {
        return DB::table($table)->select($table.'.'.$column)->whereColumn($table.'.'.$key, $foreignKey)->limit(1);
    }

    private static function requested(string $name, array $columns): ?array
    {
        $key = request()->query($name.'_tri');
        $direction = request()->query($name.'_ordre');

        return is_string($key) && array_key_exists($key, $columns) && in_array($direction, ['asc', 'desc'], true)
            ? [$key, $direction]
            : null;
    }

    public function direction(string $key): ?string
    {
        return request()->query($this->name.'_tri') === $key && in_array(request()->query($this->name.'_ordre'), ['asc', 'desc'], true)
            ? request()->query($this->name.'_ordre')
            : null;
    }

    // Lien vers ce tri, ou vers l'ordre habituel sans colonne, en revenant à la première page.
    public function url(?string $key = null, ?string $direction = null): string
    {
        return request()->fullUrlWithQuery([
            $this->name.'_tri' => $key,
            $this->name.'_ordre' => $direction,
            $this->name.'_page' => null,
        ]).'#t-'.$this->name;
    }

    public function count(): int
    {
        return $this->rows->total();
    }

    public function getIterator(): \Traversable
    {
        return $this->rows->getIterator();
    }

    public function __call(string $method, array $arguments)
    {
        return $this->rows->{$method}(...$arguments);
    }
}
