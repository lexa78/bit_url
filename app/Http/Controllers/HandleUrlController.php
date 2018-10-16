<?php

namespace App\Http\Controllers;

use App\Link;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HandleUrlController extends Controller
{
    const BASIS62 = 62;
    const TIME_OF_URL_LIVE = 3600;
    private static $alphabet62 = [
        0 => 0, 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9, 10 => "a", 11 => "b",
        12 => "c", 13 => "d", 14 => "e", 15 => "f", 16 => "g", 17 => "h", 18 => "i", 19 => "j", 20 => "k",
        21 => "l", 22 => "m", 23 => "n", 24 => "o", 25 => "p", 26 => "q", 27 => "r", 28 => "s", 29 => "t",
        30 => "u", 31 => "v", 32 => "w", 33 => "x", 34 => "y", 35 => "z", 36 => "A", 37 => "B", 38 => "C",
        39 => "D", 40 => "E", 41 => "F", 42 => "G", 43 => "H", 44 => "I", 45 => "J", 46 => "K", 47 => "L",
        48 => "M", 49 => "N", 50 => "O", 51 => "P", 52 => "Q", 53 => "R", 54 => "S", 55 => "T", 56 => "U",
        57 => "V", 58 => "W", 59 => "X", 60 => "Y", 61 => "Z"
    ];

    public function index()
    {
        return view('handleurl.index');
    }

    public function decrease(Request $request)
    {
        $longUrl = $request->long_url;
        $link = Link::where('long_url', $longUrl)->orderBy('id', 'desc')->first();
        if(empty($link)) {
            $link = self::saveShortUrl($longUrl);
            $link->short_url = $request->root().'/'.$link->short_url;
        } else {
            $timeDiff = time() - strtotime($link->created_at);
            if($timeDiff <= self::TIME_OF_URL_LIVE) {
                $link->short_url = $request->root().'/'.$link->short_url;
            } else {
                $link = self::saveShortUrl($longUrl);
                $link->short_url = $request->root().'/'.$link->short_url;
            }
        }
        return view('handleurl.decreased', compact('link'));
    }

    private function saveShortUrl($longUrl)
    {
        $link = new Link();
        $shortUrl = self::getShortUrl();
        $link->long_url = $longUrl;
        $link->short_url = $shortUrl;
        $link->save();
        return $link;
    }

    private static function getShortUrl()
    {
        list($usec, $sec) = explode(" ", microtime());
        $entered = $sec . substr($usec, 2, 3);
        $numbersWithOtherBasis = [];
        self::toBasis62($entered, $numbersWithOtherBasis);
        return implode('',$numbersWithOtherBasis);
    }

    private static function toBasis62($number, &$numbersWithOtherBasis)
    {
        $divisionRes = (int)($number / self::BASIS62);
        $numbersWithOtherBasis[] = self::$alphabet62[($number % self::BASIS62)];
        if($divisionRes < self::BASIS62) {
            $numbersWithOtherBasis[] = $divisionRes;
            return;
        }
        self::toBasis62($divisionRes, $numbersWithOtherBasis);
    }
}
