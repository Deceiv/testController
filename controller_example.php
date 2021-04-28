<?php

ini_set("soap.wsdl_cache_enabled", WSDL_CACHE_NONE);

class wsdlServiceController extends CController {

    public function actions() {
        return array(
            'wsdl' => array(
                'class' => 'CWebServiceAction',
                'serviceOptions' => array(
                    'wsdlUrl' => 'http://localhost/wsdl/index.php?r=wsdlService/wsdl',
                    'serviceUrl' => 'http://localhost/wsdl/index.php?r=wsdlService/wsdl&ws=1'
                ),
            ),
        );
    }

    /**
     * @param  string $id - id РєР»РёРµРЅС‚Р°
     * @return string РѕС‚РІРµС‚
     * @soap
     */
    public static function canceled($id) {
        $model_data = TestModel::model()->find(array("condition" => "c_RequestId = '" . $id . "' and DateTimeStopUTC is null", "order" => "id desc"));

        if (count($model_data)) {
            TestModel::model()->updateAll(array('c_DateTimeCanceled' => new CDbExpression('GETDATE()'), 'c_Answer' => TestModel::INFO_CANCELED), array("condition" => "RequestId = '" . $id . "' and DateTimeStop is null"));
        } elseif (count($model_data)) {
            return TestModel::INFO_NOTFOUND;
        }

        return TestModel::INFO_CANCELED;
    }

    /**
     * @param  string $id - id РєР»РёРµРЅС‚Р°
     * @param  string $phone - РЅРѕРјРµСЂ С‚РµР»РµС„РѕРЅР° РєР»РёРµРЅС‚Р°
     * @param  string $first_name - РёРјСЏ РєР»РёРµРЅС‚Р°
     * @param  string $middle_name - РѕС‚С‡РµСЃС‚РІРѕ РєР»РёРµРЅС‚Р°
     * @return string РѕС‚РІРµС‚
     * @soap
     */
    public static function addClient($id, $phone, $first_name, $middle_name) {
        $LogId = wsdlServiceController::writeMethodsLog(__FUNCTION__, $id);

        $model = new TestModel;
        $model->c_RequestId = $id;
        $model->c_Phone = $phone;
        $model->c_FirstName = $first_name;
        $model->c_MiddleName = $middle_name;
        $model->c_RemoteAddr = $_SERVER['REMOTE_ADDR'];
        $model->c_QueryString = $_SERVER['HTTP_SOAPACTION'];

        $model->CallListDescription = 'CallList_' . date('Ymd');
        $model->Phone01Original = '';
        $model->IsValidForDialer = 1;
        $model->SentToPhone = 1;

        $phones = explode(",", $model->c_Phone);
        foreach ($phones as $phone) {
            $p = preg_replace('/\D/', '', $phone);
            if (strlen($p) == 10 || strlen($p) == 11) {
                $p10 = substr($p, strlen($p) - 10, 10);
                // РµСЃР»Рё РІ РЅРѕРјРµСЂРµ РѕРґРёРЅР°РєРѕРІС‹Рµ С†РёС„СЂС‹ (10 СЃРїСЂР°РІР°)
                if (count(array_unique(str_split($p10))) <= 2 || $p10[0] === "0" || $p10[0] === "7" || ($p10[0] . $p10[1]) === "89") {
                    continue;
                }
                //РєРѕСЂСЂРµРєС‚РёСЂСѓРµРј Р·РЅР°С‡РµРЅРёРµ
                $p = '7' . $p10;
                // Р·Р°РЅРѕСЃРёРј РІ РєРѕСЂСЂРµРєС‚РЅС‹Рµ
                $model->correct_phones[] = $p;
                // РµСЃР»Рё Phone01Original РїСѓСЃС‚РѕР№, С‚Рѕ СЃС‚Р°РІРёРј РґР°РЅРЅС‹Р№ РЅРѕРјРµСЂ
                if (empty($model->Phone01Original)) {
                    $model->Phone01Original = $p;
                }
            }
        }

        if (count($model->correct_phones) === 0) {
            $model->IsValidForDialer = 0;
            $model->SentToPhone = 0;
        } else {
            // СѓР±РёСЂР°РµРј РґСѓР±Р»РёСЂСѓСЋС‰РёРµСЃСЏ РЅРѕРјРµСЂР°
            $model->correct_phones = array_unique($model->correct_phones);
            //РїРµСЂРµРёРЅРґРµРєСЃРёСЂСѓРµРј
            $model->correct_phones = array_values($model->correct_phones);

            // РґРѕРїРѕР»РЅСЏРµРј РјР°СЃСЃРёРІ РґРѕ 3 СЌР»РµРјРµРЅС‚РѕРІ РґР»СЏ С„СѓРЅРєС†РёРё list
            while (count($model->correct_phones) < 4) {
                $model->correct_phones[] = null;
            }

            // Р·Р°РЅРѕСЃРёРј РІ СЃРѕРѕС‚РІРµС‚СЃРІСѓСЋС‰РёРµ РїРѕР»СЏ С‚Р°Р±Р»РёС†С‹
            list($model->Phone01, $model->Phone02, $model->Phone03) = $model->correct_phones;

            // СѓРґР°Р»СЏРµРј РїСѓСЃС‚С‹Рµ РЅРѕРјРµСЂР°
            $model->correct_phones = array_diff($model->correct_phones, array(''));

            $TimeZoneInfo = $model->getTimeZone($model->Phone01);

            $model->TimeShiftUTC = !empty($TimeZoneInfo['TimeShiftUTC']) ? $TimeZoneInfo['TimeShiftUTC'] : 3;
            $model->TimeShiftMSK = !empty($TimeZoneInfo['TimeShiftMSK']) ? $TimeZoneInfo['TimeShiftMSK'] : 0;
        }

        if ($model->exists("IsValidForDialer = 1 and c_RequestId = '{$model->c_RequestId}'")) {
            $model->c_Answer = TestModel::INFO_DBL_ID;
            $model->ExclusionReasonId = 4;
        } else {
            $model->c_FinalState = TestModel::FS_999;
            $model->c_Answer = TestModel::INFO_999;
        }

        if (!empty($model->ExclusionReasonId) && $model->ExclusionReasonId > 0) {
            $model->DateTimeStopUTC = new CDbExpression('GETUTCDATE()');
        }
        if (!$model->save()) {
            $model->c_Answer = TestModel::INFO_UNKNOWN;
        }
        wsdlServiceController::updateMethodsLog($LogId, $model->c_Answer);
        return $model->c_Answer;
    }

}
