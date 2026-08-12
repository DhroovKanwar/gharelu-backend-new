<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCustomCakeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'customer' => [
                'firstName' => $this->first_name,
                'lastName' => $this->last_name,
                'phone' => $this->phone,
                'email' => $this->email,
            ],
            'occasion' => $this->occasion,
            'otherOccasion' => $this->other_occasion,
            'occasionDate' => $this->occasion_date?->format('Y-m-d'),
            'preferredTime' => $this->preferred_time,
            'customTime' => $this->custom_time,
            'peopleCount' => $this->people_count,
            'delivery' => [
                'type' => $this->delivery_type,
                'addressLine1' => $this->address_line_1,
                'addressLine2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'pincode' => $this->pincode,
                'landmark' => $this->landmark,
            ],
            'cake' => [
                'flavour' => $this->flavour,
                'shape' => $this->shape,
                'theme' => $this->theme,
                'message' => $this->cake_message,
                'eggless' => $this->eggless,
                'budget' => $this->budget,
            ],
            'notes' => $this->notes,
            'referenceImagePath' => $this->reference_image_path,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
