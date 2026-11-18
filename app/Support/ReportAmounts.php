<?php

namespace App\Support;

use InvalidArgumentException;

final class ReportAmounts
{
    public static function add(string $left, int|string $right): string
    {
        $right=(string)$right;
        foreach ([$left,$right] as $value) {
            if (!preg_match('/^-?\d+$/D',$value)) { throw new InvalidArgumentException('Report amounts require exact scaled integers.'); }
        }
        $negativeLeft=str_starts_with($left,'-'); $negativeRight=str_starts_with($right,'-');
        $a=ltrim(ltrim($left,'-'),'0')?:'0'; $b=ltrim(ltrim($right,'-'),'0')?:'0';
        if ($negativeLeft===$negativeRight) {
            $a=strrev($a); $b=strrev($b); $carry=0; $out='';
            for ($i=0;$i<max(strlen($a),strlen($b));$i++) {
                $sum=(int)($a[$i]??0)+(int)($b[$i]??0)+$carry;
                $out.=($sum%10); $carry=intdiv($sum,10);
            }
            if ($carry) { $out.=$carry; }
            $result=ltrim(strrev($out),'0')?:'0';
            return $negativeLeft && $result!=='0'?'-'.$result:$result;
        }
        $compare=strlen($a)<=>strlen($b);
        if ($compare===0) { $compare=strcmp($a,$b); }
        if ($compare===0) { return '0'; }
        if ($compare<0) { [$a,$b]=[$b,$a]; $negativeLeft=$negativeRight; }
        $a=strrev($a); $b=strrev($b); $borrow=0; $out='';
        for ($i=0;$i<strlen($a);$i++) {
            $digit=(int)$a[$i]-(int)($b[$i]??0)-$borrow;
            $borrow=$digit<0?1:0; $out.=($digit+($borrow?10:0));
        }
        $result=ltrim(strrev($out),'0')?:'0';
        return $negativeLeft && $result!=='0'?'-'.$result:$result;
    }

    public static function decimal(int|string $value, int $places=2): string
    {
        $value=self::add('0',$value);
        $negative=str_starts_with($value,'-');
        $digits=str_pad(ltrim($value,'-'),$places+1,'0',STR_PAD_LEFT);
        return ($negative?'-':'').substr($digits,0,-$places).'.'.substr($digits,-$places);
    }
}
