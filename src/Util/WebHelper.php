<?php
namespace Brainfit\Util;

class WebHelper
{
    /**
     * Create pagination arrows array
     *
     * @param int $iCurrentPage
     * @param int $iPagesCount
     * @param int $iSkiddingCount
     *
     * @return array
     */
    public static function paginationMaker($iCurrentPage, $iPagesCount, $iSkiddingCount = 4)
    {
        $ret = [];

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
        if ($iMaxPage > $iPagesCount)
        {
            $iAddedLeftLength = $iMaxPage-$iPagesCount;
            $iMaxPage = $iPagesCount;
        }
        else
        {
            //Last page yet invisible
            $ret[$iPagesCount] = ['label'=> '...'.$iPagesCount, 'link'=> $iPagesCount];
        }

        $iTrueMaxPage = $iMaxPage+$iAddedRightLength;
        if ($iTrueMaxPage > $iPagesCount)
            $iTrueMaxPage = $iPagesCount;

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
        $ret[$iPagesCount+2] = ['icon' => 'right', 'link' => $iCurrentPage + 1,
                                      'disabled' => $iCurrentPage >= $iPagesCount];

        ksort($ret);

        $r = [];
        foreach($ret as $item)
            $r[] = $item;

        return $r;
    }
}