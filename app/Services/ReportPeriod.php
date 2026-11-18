<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

class ReportPeriod
{
    public readonly CarbonImmutable $start;
    public readonly CarbonImmutable $end;
    public readonly CarbonImmutable $asOf;

    public function __construct(public readonly array $filters)
    {
        $this->asOf=CarbonImmutable::now('UTC');
        $this->start=CarbonImmutable::parse($filters['from_date'],'Africa/Lagos')->startOfDay()->utc();
        $this->end=CarbonImmutable::parse($filters['to_date'],'Africa/Lagos')->startOfDay()->addDay()->utc();
    }

    public function apply(Builder|EloquentBuilder $query, string $column): Builder|EloquentBuilder
    {
        return $query->where($column,'>=',$this->start->format('Y-m-d H:i:s.u'))->where($column,'<',$this->end->format('Y-m-d H:i:s.u'));
    }

    public function contains(string $value): bool
    {
        $time=CarbonImmutable::parse($value,'UTC');
        return $time->greaterThanOrEqualTo($this->start) && $time->lessThan($this->end);
    }

    public function metadata(): array
    {
        return ['from_date'=>$this->filters['from_date'],'to_date'=>$this->filters['to_date'],'timezone'=>'Africa/Lagos',
            'start_inclusive'=>$this->start->toISOString(),'end_exclusive'=>$this->end->toISOString()];
    }
}
