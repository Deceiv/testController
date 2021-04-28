<?php

ini_set("soap.wsdl_cache_enabled", WSDL_CACHE_NONE);

class wsdlServiceController extends CController {

    public function actions() {
        return [
            'wsdl' => [
                'class' => 'CWebServiceAction',
                'serviceOptions' => [
                    'wsdlUrl' => 'http://localhost/wsdl/index.php?r=wsdlService/wsdl',
                    'serviceUrl' => 'http://localhost/wsdl/index.php?r=wsdlService/wsdl&ws=1'
                ],
            ],
        ];
    }

    /**
     * @param  string $id - id клиента
     * @return string ответ
     * @soap
     */
    public static function canceled($id) {
        $model_data = TestModel::model()->find(["condition" => "c_RequestId = '" . $id . "' and DateTimeStopUTC is null", "order" => "id desc"]);

        if (count($model_data)) {
            TestModel::model()->updateAll(['c_DateTimeCanceled' => new CDbExpression('GETDATE()'), 'c_Answer' => TestModel::INFO_CANCELED], ["condition" => "RequestId = '" . $id . "' and DateTimeStop is null"]);
        } elseif (count($model_data)) {
            return TestModel::INFO_NOTFOUND;
        }

        return TestModel::INFO_CANCELED;
    }

    /**
     * @param  string $id - id клиента
     * @param  string $phone - номер телефона клиента
     * @param  string $first_name - имя клиента
     * @param  string $middle_name - отчество клиента
     * @return string ответ
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
                // если в номере одинаковые цифры (10 справа)
                if (count(array_unique(str_split($p10))) <= 2 || $p10[0] === "0" || $p10[0] === "7" || ($p10[0] . $p10[1]) === "89") {
                    continue;
                }
                //корректируем значение
                $p = '7' . $p10;
                // заносим в корректные
                $model->correct_phones[] = $p;
                // если Phone01Original пустой, то ставим данный номер
                if (empty($model->Phone01Original)) {
                    $model->Phone01Original = $p;
                }
            }
        }

        if (count($model->correct_phones) === 0) {
            $model->IsValidForDialer = 0;
            $model->SentToPhone = 0;
        } else {
            $model->correct_phones = array_unique($model->correct_phones);
            $model->correct_phones = array_values($model->correct_phones);

            // дополняем массив до 3 элементов для функции list
            while (count($model->correct_phones) < 4) {
                $model->correct_phones[] = null;
            }

            // заносим в соответсвующие поля таблицы
            list($model->Phone01, $model->Phone02, $model->Phone03) = $model->correct_phones;

            // удаляем пустые номера
            $model->correct_phones = array_diff($model->correct_phones, []);

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
