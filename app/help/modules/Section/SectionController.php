<?php

class SectionController extends cutomController {

//	データの更新のみのコントローラクラスは全て共通なので
//	全てのメソッドはカスタムコントローラーに実装する
//
//==============================================================================
// 削除アクション以外はカスタムコントローラに置く
public function DeleteAction() {
	$num = App::$Params[0];
	$this->Model->deleteRecordset($num);
	echo App::$Referer;
}

}
