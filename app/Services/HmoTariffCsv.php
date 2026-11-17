<?php

namespace App\Services;

use App\Models\Drug;
use App\Models\Service;
use App\Support\FixedPoint;
use Illuminate\Validation\ValidationException;

class HmoTariffCsv
{
    public const HEADER = ['item_type','item_code','currency','unit_price','preauth_required'];

    /** @return list<array<string,mixed>> */
    public function parse(string $csv): array
    {
        if (strlen($csv)>1048576 || $csv==='' || preg_match('//u',$csv)!==1 || str_contains($csv,"\0")) {
            $this->invalid(1,'Supply a nonempty UTF-8 CSV of at most one MiB.');
        }
        $stream=fopen('php://memory','r+');
        fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));
        rewind($stream);
        try {
            $header=fgetcsv($stream,null,',','"','');
            if ($header===false || array_map('trim',$header)!==self::HEADER) {
                $this->invalid(1,'Use exactly: '.implode(',',self::HEADER));
            }
            $rows=[]; $seen=[]; $line=1;
            while (($cells=fgetcsv($stream,null,',','"',''))!==false) {
                $line++;
                if ($cells===[null]) { continue; }
                if (count($cells)!==5 || count($rows)>=2000) { $this->invalid($line,'Supply five columns and at most 2000 rows.'); }
                [$type,$code,$currency,$price,$preauth]=array_map('trim',$cells);
                if (!in_array($type,['service','drug'],true) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$code)!==1
                    || $currency!=='NGN' || !in_array($preauth,['0','1'],true)) {
                    $this->invalid($line,'Invalid subject type/code, NGN currency or preauthorization flag.');
                }
                $record=$type==='service' ? Service::query()->where('code',$code)->lockForUpdate()->first() : Drug::query()->where('code',$code)->lockForUpdate()->first();
                if ($record===null || !$record->is_active || $type==='drug' && !StockCatalog::approved('drugs',$record)) {
                    $this->invalid($line,'The subject must exist in its approved active catalog.');
                }
                try { $amount=FixedPoint::parse($price); } catch (\InvalidArgumentException) { $this->invalid($line,'Use an exact nonnegative price of at most six decimals.'); }
                $key=$type.'|'.$record->id;
                if (isset($seen[$key])) { $this->invalid($line,'A subject is repeated in this import.'); }
                $seen[$key]=true;
                $rows[]=['service_id'=>$type==='service' ? $record->id : null,'drug_id'=>$type==='drug' ? $record->id : null,
                    'subject_key'=>$key,'amount_micros'=>$amount,'preauth_required'=>$preauth==='1',
                    'snapshot'=>['type'=>$type,'code'=>$record->code,'name'=>$record->name,'currency'=>'NGN','unit_price'=>$price]];
            }
            if ($rows===[]) { $this->invalid(2,'At least one valid tariff is required.'); }

            return $rows;
        } finally { fclose($stream); }
    }

    private function invalid(int $line,string $message): never
    {
        throw ValidationException::withMessages(['csv_content'=>['Row '.$line.': '.$message]]);
    }
}
