--- PaginatorHelper.php.orig    2023-09-04 18:41:44.682373729 +0000
+++ PaginatorHelper.php 2023-09-04 18:42:48.550244803 +0000
@@ -577,7 +577,7 @@
             $url += array_intersect_key($options, $placeholders);
             $url['?'] += array_diff_key($options, $placeholders);
         } else {
-            $url['?'] += $options;
+            $url['?'] = __cakefuzzer_array_merge($url['?'], $options);
         }
 
         $url['?'] = Hash::filter($url['?']);
