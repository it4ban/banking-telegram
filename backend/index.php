<?php

echo PHP_INT_MAX;
echo "<br>";
echo PHP_INT_SIZE;
echo "<br>";
echo 0b01010101;
echo "<br>";
echo 0o755;
echo "<br>";
echo 0755;
echo "<br>";

$x = 0.00012;
$y = 1.2e-4;

echo $x;
echo "<br>";
echo $y;
echo "<br>";

$x = 346.1256;
$y = 3.461256e+2;
echo $x;
echo "<br>";
echo $y;
echo "<br>";

$b = true;

echo "b: $b<br>";

$b++;
echo "b: $b<br>";

echo "Переменная принимает значение '345'";
echo "<br>";
echo 'Проект "Бездна" - самый дорогой проект в истории...';
echo "<br>";

$text = "Паро";
echo "Едет {$text}воз<br>";
echo "Плывет {$text}ход<br>";

echo `dir`;
echo "<br>";

$str = <<< HTML_END
Здесь располагается любой текст, который будет записан в переменную \$str. До тех
пор, пока не встретится строка HTML_END.
HTML_END;
echo $str;
echo "<br>";
