<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ExportRendering
{
    public const MAX_BYTES=5242880;
    public const MAX_ROWS=50000;

    /** @return list<array{0:string,1:string}> */
    public function rows(array $data): array
    {
        $rows=[];
        $this->flatten($data,'',$rows);
        return $rows;
    }

    private function flatten(mixed $value,string $path,array &$rows): void
    {
        if (is_array($value) || is_object($value)) {
            foreach ((array)$value as $key=>$item) { $this->flatten($item,$path===''?(string)$key:$path.'.'.$key,$rows); }
        } else {
            if (count($rows)>=self::MAX_ROWS) { $this->tooLarge(); }
            $rows[]=[$path, $value===null?'':(is_bool($value)?($value?'true':'false'):(string)$value)];
        }
    }

    public function render(array $data,string $format,string $generatedAt): string
    {
        $rows=$this->rows($data);
        if ($format==='html') {
            $content=view('exports.document',['rows'=>$rows,'generatedAt'=>$generatedAt])->render();
        } else {
            $stream=fopen('php://temp','w+');
            try {
                fputcsv($stream,['field','value'],',','"','',"\r\n");
                fputcsv($stream,['generated_at',$generatedAt],',','"','',"\r\n");
                foreach ($rows as $row) {
                    fputcsv($stream,array_map($this->csvCell(...),$row),',','"','',"\r\n");
                    if (ftell($stream)>self::MAX_BYTES) { $this->tooLarge(); }
                }
                rewind($stream); $content=stream_get_contents($stream);
            } finally { fclose($stream); }
        }
        if (strlen($content)>self::MAX_BYTES) { $this->tooLarge(); }
        return $content;
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[\s\x00-\x20]*[=+\-@]|^[\t\r\n]/u',$value)===1?"'".$value:$value;
    }

    public function tooLarge(): never
    {
        throw ValidationException::withMessages(['filters'=>'This export is too large. Select a narrower period or scope; no partial export was saved.']);
    }
}