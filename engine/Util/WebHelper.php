<?php
namespace Brainfit\Util;

class WebHelper
{
    public static function paginationMaker($page, $pagescount)
    {
        $maxpagesonlink = 5;
        /*
         * Универсальная функция готовит список ссылок на страницы.
         * Функция возвращает массив, элементы которого являются именованым массивом, где
         * CAPTION -- надпись для списка ссылок
         * PAGE -- код страницы для передачи в транзакцию (0--первая и т.д.)
         * $page -- текущая страница, где 0 -- первая
         * $pagescount -- всего страниц
         * $maxpagesonlink -- страниц максимум влево и вправо
         */
        $ret = [];

        //Предыдущая
        /*if (intval ( $page ) > 0) {
            $ret['<<<'] = $page - 1;
        }*/

        $iAddedRightLenght = 0;
        $minpage = $page - 4;
        if ($minpage <= 1)
        {
            $iAddedRightLenght = -$minpage+1+1;
            $minpage = 1;
        }
        else
        {
            //Первая страница уже скрылась
            $ret[1] = ['label'=> '1...', 'link'=> 1];
        }

        $maxpage = $page + 4;
        if ($maxpage > $pagescount)
        {
            $iAddedLeftLenght = $maxpage-$pagescount;
            $maxpage = $pagescount;
        }
        else
        {
            //Последняя еще не видна
            $ret[$pagescount] = ['label'=> '...'.$pagescount, 'link'=> $pagescount];
        }

        $iTrueMaxPage = $maxpage+$iAddedRightLenght;
        if ($iTrueMaxPage > $pagescount)
            $iTrueMaxPage = $pagescount;

        $iTrueMinPage = $minpage-$iAddedLeftLenght;
        if ($iTrueMinPage < 1)
            $iTrueMinPage = 1;

        for($i=$page;$i>=$iTrueMinPage;$i--)
            $ret[$i] = ['label'=> $i, 'link'=> $i, 'selected'=>$i == $page];

        for($i=$page;$i<=$iTrueMaxPage;$i++)
            $ret[$i] = ['label'=> $i, 'link'=> $i, 'selected'=>$i == $page];

        //Если страница одна, не показываем пагинацию вообще
        if (count($ret) == 1)
            return [];

        //prev:
        $ret[-1] = ['icon' => 'left', 'link' => $page-1, 'disabled' => $page <= 1];

        //next:
        $ret[$pagescount+2] = ['icon' => 'right', 'link' => $page + 1, 'disabled' => $page >= $pagescount];

        ksort($ret);

        return $ret;
    }
}