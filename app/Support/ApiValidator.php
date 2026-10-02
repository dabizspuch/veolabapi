<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * Validador de la API: en los mensajes, el campo aparece con su nombre tal
 * como se envía ("es_baja", "lineas.0.descuento"), no "es baja" como hace
 * Laravel, para que el cliente sepa qué campo corregir.
 */
class ApiValidator extends Validator
{
    public function getDisplayableAttribute($attribute)
    {
        $primary = $this->getPrimaryAttribute($attribute);

        foreach (array_unique([$attribute, $primary]) as $name) {
            if ($inline = $this->getAttributeFromLocalArray($name)) {
                return $inline;
            }
            if ($translated = $this->getAttributeFromTranslations($name)) {
                return $translated;
            }
        }

        return $attribute;
    }
}
