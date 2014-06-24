<?php
namespace Brainfit\Util;

class WebHelper
{
    /**
     * Create pagination arrows array
     *
     * @param $iCurrentPage
     * @param $iPagesArrowsCount
     *
     * @return array
     */
    public static function paginationMaker($iCurrentPage, $iPagesArrowsCount = 10)
    {
        $ret = [];

        $iSkiddingCount = floor(($iPagesArrowsCount-1)/2);

        $iAddedRightLength = 0;
        $iMinPage = $iCurrentPage - $iSkiddingCount;
        if ($iMinPage <= 1)
        {
            $iAddedRightLength = -$iMinPage+1+1;
            $iMinPage = 1;
        }
        else
        {
            //First page already invisible...
            $ret[1] = ['label'=> '1...', 'link'=> 1];
        }

        $iAddedLeftLength = 0;
        $iMaxPage = $iCurrentPage + $iSkiddingCount;
        if ($iMaxPage > $iPagesArrowsCount)
        {
            $iAddedLeftLength = $iMaxPage-$iPagesArrowsCount;
            $iMaxPage = $iPagesArrowsCount;
        }
        else
        {
            //Last page yet invisible
            $ret[$iPagesArrowsCount] = ['label'=> '...'.$iPagesArrowsCount, 'link'=> $iPagesArrowsCount];
        }

        $iTrueMaxPage = $iMaxPage+$iAddedRightLength;
        if ($iTrueMaxPage > $iPagesArrowsCount)
            $iTrueMaxPage = $iPagesArrowsCount;

        $iTrueMinPage = $iMinPage-$iAddedLeftLength;
        if ($iTrueMinPage < 1)
            $iTrueMinPage = 1;

        for($i=$iCurrentPage;$i>=$iTrueMinPage;$i--)
            $ret[$i] = ['label'=> $i, 'link'=> $i, 'selected'=>$i == $iCurrentPage];

        for($i=$iCurrentPage;$i<=$iTrueMaxPage;$i++)
            $ret[$i] = ['label'=> $i, 'link'=> $i, 'selected'=>$i == $iCurrentPage];

        //Если страница одна, не показываем пагинацию вообще
        if (count($ret) == 1)
            return [];

        //prev:
        $ret[-1] = ['icon' => 'left', 'link' => $iCurrentPage-1, 'disabled' => $iCurrentPage <= 1];

        //next:
        $ret[$iPagesArrowsCount+2] = ['icon' => 'right', 'link' => $iCurrentPage + 1,
                                      'disabled' => $iCurrentPage >= $iPagesArrowsCount];

        ksort($ret);

        $r = [];
        foreach($ret as $item)
            $r[] = $item;

        return $r;
    }
}