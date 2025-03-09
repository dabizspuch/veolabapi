# Veolab API REST

Veolab API REST is a set of endpoints designed to interact with Veolab, a Laboratory Information Management System (LIMS), providing seamless integration and automation capabilities for laboratory workflows.

## Table of Contents

- [Overview](#overview)
- [Getting Started](#getting-started)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Configuration](#configuration)
- [Authentication](#authentication)
- [Endpoints](#endpoints)
  - [General Information](#general-information)
  - [Endpoint Reference](#endpoint-reference)
- [Deployment](#deployment)
  - [Web Server Configuration](#web-server-configuration)
  - [Optimization](#optimization)
- [Examples](#examples)
  - [Basic Usage](#basic-usage)
  - [Code Examples](#code-examples)
- [Error Handling](#error-handling)
- [Best Practices](#best-practices)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

---

## Overview

The Veolab API REST allows external systems to integrate with the Veolab LIMS platform. It provides endpoints for authentication, operation management, and other laboratory-related processes. This documentation will guide you through setting up and using the API effectively.

## Getting Started

### Requirements

- Veolab `^2.0.133`
- PHP `^8.0`
- A web server (e.g., Apache or Nginx)
- MySQL database
- Composer (Dependency Manager)

### Installation

1. Clone the repository into a folder named `api`:
   ```bash
   git clone https://github.com/dabizspuch/veolabapi.git api
   cd api
   ```   
2. Install dependencies using Composer:
   ```bash
   composer install
   ```
3. Set up your environment file (`.env`) with database credentials and other configurations.
4. Generate the application key:
   ```bash
   php artisan key:generate
   ```
5. Run database migrations:
   ```bash
   php artisan migrate
   ```
6. Configure your web server to serve the application (see [Deployment](#deployment) for details).

### Configuration

1. **Set up the `.env` file**  
   The `.env` file contains essential configuration values for your application. Follow these steps to configure it:

   - Locate the `.env.example` file in the project root.
   - Create a new `.env` file by copying the example file:
     ```bash
     cp .env.example .env
     ```
   - Open the `.env` file in a text editor and update the following values:

     - **Database Configuration**: Set the database username and password:
       ```dotenv
       DB_USERNAME=your_database_user
       DB_PASSWORD=your_database_password
       ```

2. **Clear and cache the configuration**  
   After updating the `.env` file, run the following commands to clear and cache the configuration:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

## Authentication

The API uses token-based authentication. Clients must authenticate to access protected endpoints.

### Login Endpoint

- **URL:** `/api/login`
- **Method:** `POST`
- **Request Body:**
  ```json
  {
    "name": "user@example.com",
    "password": "your_password"
  }
  ```
- **Response:**
  ```json
  {
    "token": "your_token_here"
  }
  ```

### Token Usage

Include the token in the `Authorization` header for protected endpoints:

```http
Authorization: Bearer your_token_here
```

### Creating a User

To log in and test the authentication, you need at least one user in the database. You can create a user by using a database seeder. 

1. Open the `DatabaseSeeder` class (usually located at `database/seeders/DatabaseSeeder.php`).
2. Add the following code to create an admin user with predefined credentials. **The `name` of the user must match the exact name of the Veolab database that the client will use.** This is critical because the application dynamically connects to the database based on the authenticated user's name.

   ```php
   <?php

   namespace Database\Seeders;

   use Illuminate\Database\Seeder;
   use App\Models\User;
   use Illuminate\Support\Facades\Hash;

   class DatabaseSeeder extends Seeder
   {
       /**
        * Seed the application's database.
        */
       public function run(): void
       {
           User::factory()->create([               
               'name' => 'veolab',  // Replace 'veolab' with the exact name of the database
               'email' => 'veolab@example.com',
               'password' => Hash::make('your_password') // Replace 'your_password' as needed
           ]);
       }
   }
   ```
3. Run the seeder command to populate the database:
   ```bash
   php artisan db:seed
   ```

  This will create a user with the following credentials:

  - **Email:** `veolab@example.com`
  - **Password:** `your_password`

## Deployment

### Web Server Configuration

#### Apache

Create a virtual host configuration for your Laravel application. Replace `/path/to/api` with the actual path to your Laravel application:

```apache
<VirtualHost *:80>
    ServerName your_server_ip_or_domain

    DocumentRoot /path/to/api/public

    <Directory /path/to/api/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/laravel_error.log
    CustomLog ${APACHE_LOG_DIR}/laravel_access.log combined
</VirtualHost>
```

Enable the site and restart Apache:

```bash
sudo a2ensite laravel.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Optimization

For production environments, optimize your application by running these commands:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Ensure the `storage` and `bootstrap/cache` directories are writable:

```bash
sudo chown -R www-data:www-data /path/to/api
sudo chmod -R 775 /path/to/api/storage /path/to/api/bootstrap/cache
```

## Endpoints

### General Information

- **Base URL:** `https://your-domain.com/api`
- **Headers:**
  - `Content-Type: application/json`
  - `Authorization: Bearer your_token_here` (for protected endpoints)
- **Response Format:** JSON

### Endpoint Reference

#### Authentication

- **Login:** `/api/login`
- **Logout:** `/api/logout`
- **Refresh Token:** `/api/refresh`

#### Operations

##### Introduction
The operations endpoint allows for various actions related to managing operations in the system. These include creating, retrieving, updating, and deleting operations. Each operation can include multiple fields covering administrative, technical, logistical, and financial information.

##### Available Endpoints

###### 1. List Operations
- **URL:** `/api/operaciones`
- **Method:** `GET`
- **Description:** Retrieves a list of operations registered in the system with optional filters, search, and pagination.
- **Parameters:**
  - **`is_deleted`** (optional): Filter by voided operations. Use `T` to display overridden records.
  - **`search`** (optional): Search term for text fields.
  - **`limit`** (optional, default: `10`): Number of records per page.
  - **`page`** (optional, default: `1`): Page number for pagination.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "serie": "25",
      "codigo": 1,
      "descripcion": "Operation 1",
      "estado": 2,
      "fecha_registro": "2025-01-03"
    },
    {
      "delegacion": "DEL002",
      "serie": "25",
      "codigo": 2,
      "descripcion": "Operation 2",
      "estado": 2,
      "fecha_registro": "2025-01-02"
    }
  ]
  ```

###### 2. Create Operation
- **URL:** `/api/operaciones`
- **Method:** `POST`
- **Description:** Creates a new operation in the system.
- **Request Body:**
  Must include one or more of the following fields (all optional unless specified otherwise):
  ```json
  {
    "delegacion": "DEL001",
    "serie": "SER001",
    "codigo": 123,
    "fecha_registro": "2025-01-03",
    "fecha_recogida": "2025-01-04",
    "precio": 100.50,
    "estado": 1,
    "observaciones": "Operation notes",
    "cliente_codigo": "CL001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 123,
      "serie": "SER001",
      "delegacion": "DEL001",
    }
  }
  ```

###### 3. Retrieve Operation Details
- **URL:** `/api/operaciones/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific operation.
- **Parameters:**
  - `codigo` (required): Identifier for the operation.
  - `delegacion` (optional): Delegation associated with the operation.
  - `clave1` (optional): Series associated with the operation.
- **Example Response:**
  ```json
  {
    "codigo": 123,
    "delegacion": "DEL001",
    "serie": "SER001",
    "estado": 1,
    "descripcion": "Test operation",
    "fecha_registro": "2025-01-03",
    "precio": 100.50
  }
  ```

###### 4. Update Operation
- **URL:** `/api/operations/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `PUT`
- **Description:** Updates an existing operation.
- **Parameters:**
  - `codigo` (required): Identifier for the operation.
  - `delegacion` (optional): Delegation associated with the operation.
  - `clave1` (optional): Series associated with the operation.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated description",
    "estado": 2,
    "precio": 150.00
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente",
  }
  ```

###### 5. Delete Operation
- **URL:** `/api/operations/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `DELETE`
- **Description:** Deletes an operation from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the operation.
  - `delegacion` (optional): Delegation associated with the operation.
  - `clave1` (optional): Series associated with the operation.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

---

##### Available Fields for Operations
The API supports a wide range of fields that can be included in requests. Below are all available fields:

| **Field**                         | **Type**       | **Description**                                    |
|-----------------------------------|----------------|----------------------------------------------------|
| `delegacion`                      | `string`       | Delegation code.                                   |
| `serie`                           | `string`       | Series associated with the operation.              |
| `codigo`                          | `integer`      | Identifier for the operation.                      |
| `informacion`                     | `string`       | Additional information about the operation.        |
| `fecha_registro`                  | `date`         | Registration date of the operation.                |
| `fecha_recogida`                  | `date`         | Sample collection date.                            |
| `fecha_recepcion`                 | `date`         | Reception date of the sample.                      |
| `fecha_preparada`                 | `date`         | Date the sample was prepared.                      |
| `fecha_inicio`                    | `date`         | Start date of the operation.                       |
| `fecha_fin`                       | `date`         | End date of the operation.                         |
| `descripcion`                     | `string`       | Detailed description of the operation.             |
| `fecha_validacion`                | `date`         | Validation date of the operation.                  |
| `fecha_informe`                   | `date`         | Report generation date.                            |
| `fecha_envio`                     | `date`         | Date the report was sent.                          |
| `fecha_archivo`                   | `date`         | Archive date of the operation.                     |
| `fecha_anulacion`                 | `date`         | Cancellation date of the operation.                |
| `fecha_compromiso`                | `date`         | Commitment date for the operation.                 |
| `fecha_descarte`                  | `date`         | Discard date of the operation.                     |
| `referencia`                      | `string`       | Reference for the operation.                       |
| `tipo`                            | `string`       | Type of operation (`I` = Input, `E` = Output).     |
| `tipo_analisis`                   | `integer`      | Type of analysis code.                             |
| `precio`                          | `numeric`      | Price of the operation.                            |
| `descuento`                       | `string`       | Discount applied to the operation.                 |
| `observaciones`                   | `string`       | Additional notes for the operation.                |
| `tecnicas`                        | `string`       | Techniques associated with the operation.          |
| `recolector`                      | `string`       | Collector's name.                                  |
| `lugar_recogida`                  | `string`       | Place where the sample was collected.              |
| `temperatura`                     | `string`       | Temperature during sample collection.              |
| `es_urgente`                      | `string`       | Indicates urgency (`T` = True, `F` = False).       |
| `estado`                          | `integer`      | Status of the operation (`0-7`).                   |
| `es_baja`                         | `string`       | Indicates if the operation is deactivated.         |
| `es_facturada`                    | `string`       | Indicates if the operation has been billed.        |
| `es_facturable`                   | `string`       | Indicates if the operation is billable.            |
| `estado_igeo`                     | `string`       | IGEO status of the operation.                      |
| `identificador_igeo`              | `integer`      | Unique IGEO identifier for the operation.          |
| `cantidad`                        | `string`       | Quantity of samples or items in the operation.     |
| `unidad`                          | `string`       | Measurement unit for the quantity.                 |
| `tipo_desglose`                   | `string`       | Breakdown type (`S`, `T`, `N`, `O`).               |
| `lote`                            | `string`       | Lot associated with the operation.                 |
| `marca`                           | `string`       | Brand associated with the operation.               |
| `envase`                          | `string`       | Packaging details.                                 |
| `numero_envases`                  | `numeric`      | Number of packages.                                |
| `latitud`                         | `string`       | Latitude for the sample collection point.          |
| `longitud`                        | `string`       | Longitude for the sample collection point.         |
| `direccion_gps`                   | `string`       | GPS address of the collection point.               |
| `tipo_muestreo`                   | `string`       | Type of sampling (`P`, `C`, `I`).                  |
| `es_control`                      | `string`       | Indicates if it is a control operation.            |
| `id_red_sinac`                    | `integer`      | SINAC network ID.                                  |
| `codigo_localidad_sinac`          | `integer`      | Local SINAC code.                                  |
| `direccion_sinac`                 | `string`       | Address associated with SINAC.                     |
| `tipo_operacion_delegacion`       | `string`       | Delegation of the operation type.                  |
| `tipo_operacion_codigo`           | `integer`      | Code of the operation type.                        |
| `matriz_delegacion`               | `string`       | Matrix delegation for the operation.               |
| `matriz_codigo`                   | `integer`      | Matrix code.                                       |
| `equipamiento_delegacion`         | `string`       | Delegation code for the equipment.                 |
| `equipamiento_codigo`             | `string`       | Equipment code.                                    |
| `cliente_delegacion`              | `string`       | Delegation code for the client.                    |
| `cliente_codigo`                  | `string`       | Client code.                                       |
| `punto_muestreo_codigo`           | `integer`      | Sampling point code.                               |
| `contrato_delegacion`             | `string`       | Delegation code for the contract.                  |
| `contrato_serie`                  | `string`       | Series associated with the contract.               |
| `contrato_codigo`                 | `integer`      | Contract code.                                     |
| `presupuesto_delegacion`          | `string`       | Delegation code for the budget.                    |
| `presupuesto_serie`               | `string`       | Series associated with the budget.                 |
| `presupuesto_codigo`              | `integer`      | Budget code.                                       |
| `empleado_recolector_delegacion`  | `string`       | Delegation code for the collecting employee.       |
| `empleado_recolector_codigo`      | `integer`      | Code for the collecting employee.                  |
| `lote_delegacion`                 | `string`       | Delegation code for the lot.                       |
| `lote_serie`                      | `string`       | Series associated with the lot.                    |
| `lote_codigo`                     | `string`       | Lot code.                                          |
| `lote_relacionado_delegacion`     | `string`       | Delegation code for the related lot.               |
| `lote_relacionado_serie`          | `string`       | Series associated with the related lot.            |
| `lote_relacionado_codigo`         | `string`       | Code for the related lot.                          |
| `factura_delegacion`              | `string`       | Delegation code for the invoice.                   |
| `factura_serie`                   | `string`       | Series associated with the invoice.                |
| `factura_codigo`                  | `integer`      | Invoice code.                                      |
| `planificacion_delegacion`        | `string`       | Delegation code for the planification.             |
| `planificacion_codigo`            | `integer`      | Planification code.                                |
| `fecha_codigo`                    | `integer`      | Code associated with the operation's date.         |
| `dictamen_delegacion`             | `string`       | Delegation code for the ruling.                    |
| `dictamen_codigo`                 | `integer`      | Ruling code.                                       |
| `tarifa_delegacion`               | `string`       | Delegation code for the tariff.                    |
| `tarifa_codigo`                   | `integer`      | Tariff code.                                       |
| `proveedor_delegacion`            | `string`       | Delegation code for the provider.                  |
| `proveedor_codigo`                | `string`       | Provider code.                                     |
| `producto_delegacion`             | `string`       | Delegation code for the product.                   |
| `producto_codigo`                 | `string`       | Product code.                                      |
| `serie_lote_numero`               | `string`       | Series number for the lot.                         |
| `tecnica_delegacion`              | `string`       | Delegation code for the technique.                 |
| `tecnica_codigo`                  | `string`       | Technique code.                                    |
| `operacion_control_delegacion`    | `string`       | Delegation code for the control operation.         |
| `operacion_control_serie`         | `string`       | Series associated with the control operation.      |
| `operacion_control_codigo`        | `integer`      | Unique identifier for the control operation.       |
| `servicios`                       | `array`        | List of services associated with the operation.    |
| `servicios.*.delegacion`          | `string`       | Delegation code for the service.                   |
| `servicios.*.codigo`              | `string`       | Service code.                                      |
| `autodefinibles`                  | `array`        | List of custom fields for the operation.           |
| `autodefinibles.*`                | `string`       | Value of an individual custom field.               |

---

##### Validation Rules
Each field has specific rules to ensure the integrity of the data sent. Below are the validation rules for all available fields:

- `delegacion`: `nullable|string|max:10`
- `serie`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `informacion`: `nullable|string|max:20`
- `fecha_registro`: `nullable|date`
- `fecha_recogida`: `nullable|date`
- `fecha_recepcion`: `nullable|date`
- `fecha_preparada`: `nullable|date`
- `fecha_inicio`: `nullable|date`
- `fecha_fin`: `nullable|date`
- `fecha_validacion`: `nullable|date`
- `fecha_informe`: `nullable|date`
- `fecha_envio`: `nullable|date`
- `fecha_archivo`: `nullable|date`
- `fecha_anulacion`: `nullable|date`
- `fecha_compromiso`: `nullable|date`
- `fecha_descarte`: `nullable|date`
- `referencia`: `nullable|string|max:255`
- `tipo`: `nullable|string|in:I,E|max:1`
- `tipo_analisis`: `nullable|integer`
- `precio`: `nullable|numeric|min:0|max:99999999.99999`
- `descuento`: `nullable|string|max:15`
- `descripcion`: `nullable|string`
- `observaciones`: `nullable|string`
- `tecnicas`: `nullable|string`
- `recolector`: `nullable|string|max:100`
- `lugar_recogida`: `nullable|string|max:100`
- `temperatura`: `nullable|string|max:50`
- `es_urgente`: `nullable|string|in:T,F|max:1`
- `estado`: `nullable|integer|in:0,1,2,3,4,5,6,7`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `es_facturada`: `nullable|string|in:T,F|max:1`
- `es_facturable`: `nullable|string|in:T,F|max:1`
- `estado_igeo`: `nullable|string|max:1`
- `identificador_igeo`: `nullable|integer`
- `cantidad`: `nullable|string|max:50`
- `unidad`: `nullable|string|max:15`
- `tipo_desglose`: `nullable|string|in:S,T,N,O|max:1`
- `lote`: `nullable|string|max:70`
- `marca`: `nullable|string|max:50`
- `envase`: `nullable|string|max:255`
- `numero_envases`: `nullable|numeric|min:0|max:99999999.99999`
- `latitud`: `nullable|string|max:20`
- `longitud`: `nullable|string|max:20`
- `direccion_gps`: `nullable|string|max:255`
- `tipo_muestreo`: `nullable|string|in:P,C,I|max:1`
- `es_control`: `nullable|string|in:T,F|max:1`
- `id_red_sinac`: `nullable|integer`
- `codigo_localidad_sinac`: `nullable|integer`
- `direccion_sinac`: `nullable|string|max:200`
- `tipo_operacion_delegacion`: `nullable|string|max:10`
- `tipo_operacion_codigo`: `nullable|integer`
- `matriz_delegacion`: `nullable|string|max:10`
- `matriz_codigo`: `nullable|integer`
- `equipamiento_delegacion`: `nullable|string|max:10`
- `equipamiento_codigo`: `nullable|string|max:20`
- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `nullable|string|max:15`
- `punto_muestreo_codigo`: `nullable|integer`
- `contrato_delegacion`: `nullable|string|max:10`
- `contrato_serie`: `nullable|string|max:10`
- `contrato_codigo`: `nullable|integer`
- `presupuesto_delegacion`: `nullable|string|max:10`
- `presupuesto_serie`: `nullable|string|max:10`
- `presupuesto_codigo`: `nullable|integer`
- `empleado_recolector_delegacion`: `nullable|string|max:10`
- `empleado_recolector_codigo`: `nullable|integer`
- `lote_delegacion`: `nullable|string|max:10`
- `lote_serie`: `nullable|string|max:10`
- `lote_codigo`: `nullable|string|max:50`
- `lote_relacionado_delegacion`: `nullable|string|max:10`
- `lote_relacionado_serie`: `nullable|string|max:10`
- `lote_relacionado_codigo`: `nullable|string|max:50`
- `factura_delegacion`: `nullable|string|max:10`
- `factura_serie`: `nullable|string|max:10`
- `factura_codigo`: `nullable|integer`
- `planificacion_delegacion`: `nullable|string|max:10`
- `planificacion_codigo`: `nullable|integer`
- `fecha_codigo`: `nullable|integer`
- `dictamen_delegacion`: `nullable|string|max:10`
- `dictamen_codigo`: `nullable|integer`
- `tarifa_delegacion`: `nullable|string|max:10`
- `tarifa_codigo`: `nullable|integer`
- `proveedor_delegacion`: `nullable|string|max:10`
- `proveedor_codigo`: `nullable|string|max:15`
- `producto_delegacion`: `nullable|string|max:10`
- `producto_codigo`: `nullable|string|max:15`
- `serie_lote_numero`: `nullable|string|max:30`
- `tecnica_delegacion`: `nullable|string|max:10`
- `tecnica_codigo`: `nullable|string|max:30`
- `operacion_control_delegacion`: `nullable|string|max:10`
- `operacion_control_serie`: `nullable|string|max:10`
- `operacion_control_codigo`: `nullable|integer`
- `servicios`: `nullable|array`
  - **`servicios.*.delegacion`**: `nullable|string|max:10`
  - **`servicios.*.codigo`**: `nullable|string|max:20`
- `autodefinibles`: `nullable|array`
  - **`autodefinibles.*`**: `nullable|string`

---

#### Parameters for Operations

##### Introduction
The Operations Parameters endpoint allows for managing specific parameters (techniques) related to operations in the system. These parameters include detailed information about the methodology, accreditation, and additional metadata associated with each operation.

##### Available Endpoints

###### 1. List Parameters
- **URL:** `/api/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves a list of parameters associated with a specific operation.
- **Parameters:**
  - **`codigo`** (required): Identifier for the operation.
  - **`delegacion`** (optional): Delegation code associated with the operation.
  - **`clave1`** (optional): Series associated with the operation.
- **Example Response:**
  ```json
  [
    {
      "operacion_delegacion": "DEL01",
      "operacion_serie": "SER01",
      "operacion_codigo": 1,
      "parametro_delegacion": "",
      "parametro_codigo": "TEC01",
      "nombre": "Parameter Name"
    },
    {
      "operacion_delegacion": "DEL02",
      "operacion_serie": "SER02",
      "operacion_codigo": 2,
      "parametro_delegacion": "",
      "parametro_codigo": "TEC02",
      "nombre": "Another Parameter Name"
    }
  ]
  ```

###### 2. Retrieve Parameter Details
- **URL:** `/api/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific parameter associated with an operation.
- **Parameters:**
  - **`codigo`** (required): Identifier for the operation.
  - **`delegacion`** (optional): Delegation code associated with the operation.
  - **`clave1`** (optional): Series associated with the operation.
  - **`clave2`** (required): Identifier for the parameter.
  - **`clave3`** (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  {
    "operacion_delegacion": "DEL01",
    "operacion_serie": "SER01",
    "operacion_codigo": 1,
    "parametro_delegacion": "",
    "parametro_codigo": "TEC01",
    "nombre": "Parameter Name",
    "metodologia": "Methodology details",
    "normativa": "Regulations",
    "precio": 100.50
  }
  ```

###### 3. Create a Parameter
- **URL:** `/api/operaciones-parametros`
- **Method:** `POST`
- **Description:** Adds a new parameter to an operation.
- **Request Body:**
  ```json
  {
    "operacion_delegacion": "DEL01",
    "operacion_serie": "SER01",
    "operacion_codigo": 1,
    "parametro_delegacion": "DEL01",
    "parametro_codigo": "TEC01",
    "nombre": "Parameter Name",
    "metodologia": "Methodology details",
    "precio": 100.50,
    "servicio_delegacion": "DEL01",
    "servicio_codigo": "SERV01"  
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "operacion_codigo": 1,
      "operacion_serie": "SER01",
      "operacion_delegacion": "DEL01"
    }
  }
  ```

##### 4. Update a Parameter
- **URL:** `/api/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}`
- **Method:** `PUT`
- **Description:** Updates details of a specific parameter associated with an operation.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Parameter Name",
    "precio": 120.00
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

##### 5. Delete a Parameter
- **URL:** `/api/operaciones-parametros/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}`
- **Method:** `DELETE`
- **Description:** Deletes a parameter from an operation.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

---

#### Available Fields for Parameters
The API supports the following fields for parameters associated with operations:

| **Field**                 | **Type**   | **Description**                                             |
|---------------------------|------------|-------------------------------------------------------------|
| `operacion_delegacion`    | `string`   | Delegation code for the operation.                          |
| `operacion_serie`         | `string`   | Series associated with the operation.                       |
| `operacion_codigo`        | `integer`  | Identifier for the operation.                               |
| `parametro_delegacion`    | `string`   | Delegation code for the parameter.                          |
| `parametro_codigo`        | `string`   | Identifier for the parameter.                               |
| `nombre`                  | `string`   | Name of the parameter.                                      |
| `nombre_informes`         | `string`   | Name of the parameter for reports.                          |
| `metodologia`             | `string`   | Methodology details for the parameter.                      |
| `normativa`               | `string`   | Regulations associated with the parameter.                  |
| `precio`                  | `numeric`  | Price of the parameter.                                     |
| `descuento`               | `string`   | Discount applied to the parameter.                          |
| `unidades`                | `string`   | Units for the parameter.                                    |
| `leyenda`                 | `string`   | Legend or description for the parameter.                    |
| `metodologia_abreviada`   | `string`   | Abbreviated methodology details.                            |
| `tiempo_prueba`           | `integer`  | Time required for the test (in minutes).                    |
| `fecha_acreditacion`      | `date`     | Accreditation date for the parameter.                       |
| `es_exportable`           | `string`   | Indicates if the parameter is exportable (`T`, `F`).        |
| `es_cursiva`              | `string`   | Indicates if the name is displayed in italics (`T`, `F`).   |

---

#### Validation Rules for Parameters
Below are the validation rules applied to the fields when creating or updating parameters:

- `operacion_delegacion`: `nullable|string|max:10`
- `operacion_serie`: `nullable|string|max:10`
- `operacion_codigo`: `nullable|integer`
- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `nullable|string|max:30`
- `nombre`: `nullable|string|max:255`
- `nombre_informes`: `nullable|string|max:255`
- `es_cursiva`: `nullable|in:T,F|max:1`
- `fecha_acreditacion`: `nullable|date`
- `parametro`: `nullable|string|max:100`
- `abreviatura`: `nullable|string|max:50`
- `numero_cas`: `nullable|string|max:50`
- `precio`: `nullable|numeric`
- `descuento`: `nullable|string|max:15`
- `unidades`: `nullable|string|max:50`
- `leyenda`: `nullable|string|max:100`
- `metodologia`: `nullable|string|max:255`
- `metodologia_abreviada`: `nullable|string|max:255`
- `normativa`: `nullable|string|max:100`
- `tiempo_prueba`: `nullable|integer`
- `limite_cuantificacion`: `nullable|string|max:50`
- `valor_minimo_detectable`: `nullable|string|max:50`
- `incertidumbre`: `nullable|string|max:50`
- `instruccion`: `nullable|string`
- `es_exportable`: `nullable|in:T,F|max:1`
- `posicion`: `nullable|integer`
- `es_agrupada`: `nullable|in:T,F|max:1`
- `fecha_inicio`: `nullable|date`
- `fecha_fin`: `nullable|date`
- `seccion_delegacion`: `nullable|string|max:10`
- `seccion_codigo`: `nullable|integer`
- `analista_delegacion`: `nullable|string|max:10`
- `analista_codigo`: `nullable|integer`
- `servicio_delegacion`: `nullable|string|max:10`
- `servicio_codigo`: `nullable|string|max:20`

#### Results of Operations

##### Introduction
The results endpoint allows for various actions related to managing the results of operations in the system. These include retrieving and updating results for specific operations. Each result is linked to an operation and contains detailed analytical and descriptive information.

##### Available Endpoints

###### 1. List Results
- **URL:** `/api/operaciones-resultados/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves a list of results associated with a specific operation. The results can be filtered using optional parameters.
- **Parameters:**
  - **`codigo`** (required): Identifier for the operation.
  - **`delegacion`** (optional): Delegation code associated with the operation.
  - **`clave1`** (optional): Series associated with the operation.
- **Example Response:**
  ```json
  [
    {
        "operacion_delegacion": "DEL01",
        "operacion_serie": "SER01",
        "operacion_codigo": 1,
        "parametro_delegacion": "",
        "parametro_codigo": "14",
        "numero_columna": 1,
        "valor": "0,602059991327962"
    },
    {
        "operacion_delegacion": "",
        "operacion_serie": "08",
        "operacion_codigo": 1,
        "parametro_delegacion": "",
        "parametro_codigo": "15",
        "numero_columna": 1,
        "valor": "56"
    }
  ]

###### 2. Retrieve Detailed Results
- **URL:** `/api/operaciones-resultados/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}`
- **Method:** `GET`
- **Description:** Retrieves a list of results associated with a specific operation. The results can be filtered using optional parameters.
- **Parameters:**
  - **`codigo`** (required): Identifier for the operation.
  - **`delegacion`** (optional): Delegation code associated with the operation.
  - **`clave1`** (optional): Series associated with the operation.
  - **`clave2`** (required): Identifier for the parameter.
  - **`clave3`** (optional): Delegation code associated with the parameter.
  - **`clave4`** (required): Column number in results grid.
- **Example Response:**
  ```json
  [
    {
        "operacion_delegacion": "DEL01",
        "operacion_serie": "SER01",
        "operacion_codigo": 1,
        "parametro_delegacion": "",
        "parametro_codigo": "14",
        "numero_columna": 1,
        "valor": "0,602059991327962"
    },
    {
        "operacion_delegacion": "DEL01",
        "operacion_serie": "SER01",
        "operacion_codigo": 1,
        "parametro_delegacion": "",
        "parametro_codigo": "15",
        "numero_columna": 1,
        "valor": "56"
    }
  ]

###### 3. Update Results
- **URL:** `/api/operaciones-resultados/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}`
- **Method:** `PUT`
- **Description:** Updates the values of specific results for an operation. Allows modifying the result of a parameter associated with a specific operation.
- **Parameters:**
  - **`codigo`** (required): Identifier for the operation.
  - **`delegacion`** (optional): Delegation code associated with the operation.
  - **`clave1`** (optional): Series associated with the operation.
  - **`clave2`** (required): Identifier for the parameter.
  - **`clave3`** (optional): Delegation code associated with the parameter.
  - **`clave4`** (required): Column number in results grid.
- **Request Body:**
  ```json
  {
    "valor": "7.25"
  }
  ```
- **Example Response:**
  ```json
  {
      "message": "Registro actualizado correctamente"
  }
  ```

##### Available Fields for Results
The API supports the following fields when working with operation results:

| **Field**                 | **Type**   | **Description**                                             |
|---------------------------|------------|-------------------------------------------------------------|
| `operacion_delegacion`    | `string`   | Delegation code for the operation.                          |
| `operacion_serie`         | `string`   | Series associated with the operation.                       |
| `operacion_codigo`        | `integer`  | Identifier for the operation.                               |
| `parametro_delegacion`    | `string`   | Delegation code for the parameter.                          |
| `parametro_codigo`        | `string`   | Identifier for the parameter associated with the result.    |
| `numero_columna`          | `integer`  | Column number in the results grid.                          |
| `valor`                   | `string`   | Value of the result for the specified parameter and column. |
| `titulo`                  | `string`   | Title or description of the result.                         |
| `titulo2`                 | `string`   | Secondary title or description.                             |
| `titulo3`                 | `string`   | Tertiary title or description.                              |
| `es_visible_informes`     | `string`   | Indicates if the result is visible in reports (`T`, `F`).   |
| `es_visible_resultados`   | `string`   | Indicates if the result is visible in results (`T`, `F`).   |
| `es_editable`             | `string`   | Indicates if the result is editable (`T`, `F`).             |
| `es_control_exactitud`    | `string`   | Indicates if the result is for accuracy control (`T`, `F`). |
| `es_control_precision`    | `string`   | Indicates if the result is for precision control (`T`, `F`).|
| `es_activa`               | `string`   | Indicates if the result is active (`T`, `F`).               |
| `marca_delegacion`        | `string`   | Delegation code for the brand associated with the result.   |
| `marca_codigo`            | `integer`  | Brand code associated with the result.                      |

---

##### Validation Rules for Results
Below are the validation rules applied to the fields when creating or updating results:

- `operacion_delegacion`: `nullable|string|max:10`
- `operacion_serie`: `nullable|string|max:10`
- `operacion_codigo`: `nullable|integer`
- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `nullable|string|max:30`
- `numero_columna`: `nullable|integer`
- `valor`: `nullable|string|max:255`
- `titulo`: `nullable|string|max:100`
- `titulo2`: `nullable|string|max:100`
- `titulo3`: `nullable|string|max:100`
- `es_visible_informes`: `nullable|string|in:T,F|max:1`
- `es_visible_resultados`: `nullable|string|in:T,F|max:1`
- `es_editable`: `nullable|string|in:T,F|max:1`
- `es_control_exactitud`: `nullable|string|in:T,F|max:1`
- `es_control_precision`: `nullable|string|in:T,F|max:1`
- `es_activa`: `nullable|string|in:T,F|max:1`
- `marca_delegacion`: `nullable|string|max:10`
- `marca_codigo`: `nullable|integer`

#### Orders

##### Introduction
The Orders API provides endpoints to manage orders within the system. Orders are associated with multiple operations and may include details like observations, creation date, and associated departments or techniques.

##### Available Endpoints

###### 1. List Orders
- **URL:** `/api/ordenes`
- **Method:** `GET`
- **Description:** Retrieves a list of all orders in the system.
- **Parameters:** None.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "serie": "SER001",
      "codigo": 123,
      "observaciones": "Example observation",
      "fecha_creacion": "2025-01-01",
      "fecha_impresion": null
    },
    {
      "delegacion": "DEL002",
      "serie": "SER002",
      "codigo": 124,
      "observaciones": "Another observation",
      "fecha_creacion": "2025-01-02",
      "fecha_impresion": "2025-01-03"
    }
  ]
  ```

###### 2. Retrieve Order Details
- **URL:** `/api/ordenes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific order.
- **Parameters:**
  - **`codigo`** (required): Identifier for the order.
  - **`delegacion`** (optional): Delegation code for the order.
  - **`clave1`** (optional): Series associated with the order.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "serie": "SER001",
    "codigo": 123,
    "observaciones": "Example observation",
    "fecha_creacion": "2025-01-01",
    "fecha_impresion": null,
    "departamento_delegacion": "DEP01",
    "departamento_codigo": 1,
    "tecnica_delegacion": "TEC01",
    "tecnica_codigo": "T001"
  }
  ```

###### 3. Create an Order
- **URL:** `/api/ordenes`
- **Method:** `POST`
- **Description:** Creates a new order in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "serie": "SER001",
    "codigo": 123,
    "observaciones": "New order observation",
    "fecha_creacion": "2025-01-01",
    "operaciones": [
        {
            "delegacion": "DEL001",
            "serie": "SER001",
            "codigo": 3
        }
    ],
    "analistas": [
      {
        "delegacion": "DEL001",
        "codigo": 789
      }
    ]
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 123,
      "delegacion": "DEL001",
      "serie": "SER001"
    }
  }
  ```

###### 4. Update an Order
- **URL:** `/api/ordenes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `PUT`
- **Description:** Updates the details of an existing order.
- **Parameters:**
  - **`codigo`** (required): Identifier for the order.
  - **`delegacion`** (optional): Delegation code for the order.
  - **`clave1`** (optional): Series associated with the order.
- **Request Body:**
  ```json
  {
    "observaciones": "Updated observation",
    "fecha_impresion": "2025-01-03"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Order
- **URL:** `/api/ordenes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `DELETE`
- **Description:** Deletes an existing order.
- **Parameters:**
  - **`codigo`** (required): Identifier for the order.
  - **`delegacion`** (optional): Delegation code for the order.
  - **`clave1`** (optional): Series associated with the order.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

---

##### Available Fields for Orders
The following fields are supported for orders in the system:

| **Field**                     | **Type**   | **Description**                                             |
|-------------------------------|------------|-------------------------------------------------------------|
| `delegacion`                  | `string`   | Delegation code for the order.                              |
| `serie`                       | `string`   | Series associated with the order.                           |
| `codigo`                      | `integer`  | Identifier for the order.                                   |
| `observaciones`               | `string`   | Observations related to the order.                          |
| `fecha_creacion`              | `date`     | Creation date of the order.                                 |
| `fecha_impresion`             | `date`     | Date the order was printed.                                 |
| `departamento_delegacion`     | `string`   | Delegation code for the associated department.              |
| `departamento_codigo`         | `integer`  | Code for the associated department.                         |
| `tecnica_delegacion`          | `string`   | Delegation code for the associated technique.               |
| `tecnica_codigo`              | `string`   | Code for the associated technique.                          |
| `operaciones`                 | `array`    | List of operations associated with the order.               |
| `operaciones.*.delegacion`    | `string`   | Delegation code for an operation.                           |
| `operaciones.*.serie`         | `string`   | Series associated with an operation.                        |
| `operaciones.*.codigo`        | `integer`  | Identifier for an operation.                                |
| `analistas`                   | `array`    | List of analysts associated with the order.                 |
| `analistas.*.delegacion`      | `string`   | Delegation code for an analyst.                             |
| `analistas.*.codigo`          | `integer`  | Identifier for an analyst.                                  |

---

##### Validation Rules for Orders
The following validation rules apply when creating or updating orders:

- `delegacion`: `nullable|string|max:10`
- `serie`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `observaciones`: `nullable|string|max:255`
- `fecha_creacion`: `nullable|date`
- `fecha_impresion`: `nullable|date`
- `departamento_delegacion`: `nullable|string|max:10`
- `departamento_codigo`: `nullable|integer`
- `tecnica_delegacion`: `nullable|string|max:10`
- `tecnica_codigo`: `nullable|string|max:30`
- `operaciones`: `required|array|min:1` (when creating)
  - **`operaciones.*.delegacion`**: `nullable|string|max:10`
  - **`operaciones.*.serie`**: `nullable|string|max:10`
  - **`operaciones.*.codigo`**: `required|integer`
- `analistas`: `nullable|array`
  - **`analistas.*.delegacion`**: `nullable|string|max:10`
  - **`analistas.*.codigo`**: `nullable|integer`

#### Orders Operations

##### Introduction
The orders operations endpoints allow managing the operations associated with specific orders. These endpoints provide functionality for creating, retrieving, and deleting operations within the system.

##### Available Endpoints

###### 1. List Operations for an Order
- **URL:** `/ordenes-operaciones/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves the list of operations associated with a specific order.
- **Parameters:**
  - `codigo` (required): Identifier for the order.
  - `delegacion` (optional): Delegation code associated with the order.
  - `clave1` (optional): Series associated with the order.
- **Example Response:**
  ```json
  [
    {
      "orden_delegacion": "ORD001",
      "orden_serie": "SER001",
      "orden_codigo": 1,
      "operacion_delegacion": "OPE001",
      "operacion_serie": "SER001",
      "operacion_codigo": 101
    },
    {
      "orden_delegacion": "ORD002",
      "orden_serie": "SER002",
      "orden_codigo": 2,
      "operacion_delegacion": "OPE002",
      "operacion_serie": "SER002",
      "operacion_codigo": 102
    }
  ]
  ```

###### 2. Retrieve Operation Details for an Order
- **URL:** `/ordenes-operaciones/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific operation associated with an order.
- **Parameters:**
  - `codigo` (required): Identifier for the order.
  - `delegacion` (optional): Delegation code associated with the order.
  - `clave1` (optional): Series associated with the order.
  - `clave2` (required): Identifier for the operation.
  - `clave3` (optional): Series associated with the operation.
  - `clave4` (optional): Delegation code associated with the operation.
- **Example Response:**
  ```json
  {
    "orden_delegacion": "ORD001",
    "orden_serie": "SER001",
    "orden_codigo": 1,
    "operacion_delegacion": "OPE001",
    "operacion_serie": "SER001",
    "operacion_codigo": 101
  }
  ```

###### 3. Create an Operation for an Order
- **URL:** `/ordenes-operaciones`
- **Method:** `POST`
- **Description:** Creates a new operation and associates it with an order.
- **Request Body:**
  ```json
  {
    "orden_delegacion": "ORD001",
    "orden_serie": "SER001",
    "orden_codigo": 1,
    "operacion_delegacion": "OPE001",
    "operacion_serie": "SER001",
    "operacion_codigo": 101
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente"
  }
  ```

###### 4. Delete an Operation from an Order
- **URL:** `/ordenes-operaciones/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific operation from an order.
- **Parameters:**
  - `codigo` (required): Identifier for the order.
  - `delegacion` (optional): Delegation code associated with the order.
  - `clave1` (optional): Series associated with the order.
  - `clave2` (required): Identifier for the operation.
  - `clave3` (optional): Series associated with the operation.
  - `clave4` (optional): Delegation code associated with the operation.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Validation Rules
The following validation rules are applied when creating or updating operations:

| **Field**                | **Type**   | **Validation Rules**              |
|--------------------------|------------|-----------------------------------|
| `orden_delegacion`       | `string`   | `nullable|string|max:10`          |
| `orden_serie`            | `string`   | `nullable|string|max:10`          |
| `orden_codigo`           | `integer`  | `required|integer`                |
| `operacion_delegacion`   | `string`   | `nullable|string|max:10`          |
| `operacion_serie`        | `string`   | `nullable|string|max:10`          |
| `operacion_codigo`       | `integer`  | `required|integer`                |

---

#### Orders Analysts

##### Introduction
The orders analysts endpoints allow managing the analysts assigned to specific orders. These endpoints provide functionality for creating, retrieving, and deleting analysts associated with orders in the system.

##### Available Endpoints

###### 1. List Analysts for an Order
- **URL:** `/ordenes-analistas/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves the list of analysts assigned to a specific order.
- **Parameters:**
  - `codigo` (required): Identifier for the order.
  - `delegacion` (optional): Delegation code associated with the order.
  - `clave1` (optional): Series associated with the order.
- **Example Response:**
  ```json
  [
    {
      "orden_delegacion": "ORD001",
      "orden_serie": "SER001",
      "orden_codigo": 1,
      "empleado_delegacion": "EMP001",
      "empleado_codigo": 101
    },
    {
      "orden_delegacion": "ORD002",
      "orden_serie": "SER002",
      "orden_codigo": 2,
      "empleado_delegacion": "EMP002",
      "empleado_codigo": 102
    }
  ]
  ```

###### 2. Retrieve Analyst Details for an Order
- **URL:** `/ordenes-analistas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific analyst assigned to an order.
- **Parameters:**
  - `codigo` (required): Identifier for the order.
  - `delegacion` (optional): Delegation code associated with the order.
  - `clave1` (optional): Series associated with the order.
  - `clave2` (required): Identifier for the analyst (employee code).
  - `clave3` (optional): Delegation code associated with the analyst.
- **Example Response:**
  ```json
  {
    "orden_delegacion": "ORD001",
    "orden_serie": "SER001",
    "orden_codigo": 1,
    "empleado_delegacion": "EMP001",
    "empleado_codigo": 101
  }
  ```

###### 3. Assign an Analyst to an Order
- **URL:** `/ordenes-analistas`
- **Method:** `POST`
- **Description:** Assigns a new analyst to an order.
- **Request Body:**
  ```json
  {
    "orden_delegacion": "ORD001",
    "orden_serie": "SER001",
    "orden_codigo": 1,
    "empleado_delegacion": "EMP001",
    "empleado_codigo": 101
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente"
  }
  ```

###### 4. Remove an Analyst from an Order
- **URL:** `/ordenes-analistas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}`
- **Method:** `DELETE`
- **Description:** Removes a specific analyst from an order.
- **Parameters:**
  - `codigo` (required): Identifier for the order.
  - `delegacion` (optional): Delegation code associated with the order.
  - `clave1` (optional): Series associated with the order.
  - `clave2` (required): Identifier for the analyst (employee code).
  - `clave3` (optional): Delegation code associated with the analyst.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Validation Rules
The following validation rules are applied when assigning or updating analysts:

| **Field**                | **Type**   | **Validation Rules**              |
|--------------------------|------------|-----------------------------------|
| `orden_delegacion`       | `string`   | `nullable|string|max:10`          |
| `orden_serie`            | `string`   | `nullable|string|max:10`          |
| `orden_codigo`           | `integer`  | `required|integer`                |
| `empleado_delegacion`    | `string`   | `nullable|string|max:10`          |
| `empleado_codigo`        | `integer`  | `required|integer`                |

---

#### Batches

##### Introduction
The batches endpoints allow managing the information related to batches in the system. These endpoints provide functionality for creating, retrieving, updating, and deleting batches.

##### Available Endpoints

###### 1. List Batches
- **URL:** `/lotes`
- **Method:** `GET`
- **Description:** Retrieves a list of all batches in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "serie": "SER001",
      "codigo": "LOT001",
      "referencia": "REF001",
      "descripcion": "Batch 1",
      "estado": 1,
      "fecha_registro": "2025-01-01"
    },
    {
      "delegacion": "DEL002",
      "serie": "SER002",
      "codigo": "LOT002",
      "referencia": "REF002",
      "descripcion": "Batch 2",
      "estado": 2,
      "fecha_registro": "2025-01-02"
    }
  ]
  ```

###### 2. Retrieve Batch Details
- **URL:** `/lotes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific batch.
- **Parameters:**
  - `codigo` (required): Identifier for the batch.
  - `delegacion` (optional): Delegation code associated with the batch.
  - `clave1` (optional): Series associated with the batch.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "serie": "SER001",
    "codigo": "LOT001",
    "referencia": "REF001",
    "descripcion": "Batch 1",
    "observaciones": "Some observations",
    "comentarios": "Additional comments",
    "fecha_registro": "2025-01-01",
    "fecha_recepcion": "2025-01-02",
    "estado": 1,
    "cliente_delegacion": "CLI001",
    "cliente_codigo": "CLI123"
  }
  ```

###### 3. Create a Batch
- **URL:** `/lotes`
- **Method:** `POST`
- **Description:** Creates a new batch in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "serie": "SER001",
    "codigo": "LOT001",
    "referencia": "REF001",
    "descripcion": "Batch 1",
    "observaciones": "Some observations",
    "comentarios": "Additional comments",
    "fecha_registro": "2025-01-01",
    "estado": 1,
    "cliente_delegacion": "CLI001",
    "cliente_codigo": "CLI123"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "LOT001",
      "serie": "SER001",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Batch
- **URL:** `/lotes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific batch.
- **Request Body:**
  ```json
  {
    "referencia": "Updated Reference",
    "descripcion": "Updated Batch Description",
    "observaciones": "Updated observations",
    "estado": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Batch
- **URL:** `/lotes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific batch from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the batch.
  - `delegacion` (optional): Delegation code associated with the batch.
  - `clave1` (optional): Series associated with the batch.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Batches
The following fields are supported for batches in the system:

| **Field**              | **Type**   | **Description**                                 |
|------------------------|------------|-------------------------------------------------|
| `delegacion`           | `string`   | Delegation code for the batch.                  |
| `serie`                | `string`   | Series associated with the batch.               |
| `codigo`               | `string`   | Identifier for the batch.                       |
| `referencia`           | `string`   | Reference for the batch.                        |
| `descripcion`          | `string`   | Description of the batch.                       |
| `observaciones`        | `string`   | Observations related to the batch.              |
| `comentarios`          | `string`   | Additional comments about the batch.            |
| `fecha_registro`       | `date`     | Registration date of the batch.                 |
| `fecha_recepcion`      | `date`     | Reception date of the batch.                    |
| `estado`               | `integer`  | Status of the batch (`0`, `6`, or `7`).         |
| `cliente_delegacion`   | `string`   | Delegation code for the associated client.      |
| `cliente_codigo`       | `string`   | Code for the associated client.                 |

---

##### Validation Rules for Batches
The following validation rules apply when creating or updating batches:

- `delegacion`: `nullable|string|max:10`
- `serie`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:50`
- `referencia`: `nullable|string|max:30`
- `descripcion`: `nullable|string|max:255`
- `observaciones`: `nullable|string|max:255`
- `comentarios`: `nullable|string|max:255`
- `fecha_registro`: `nullable|date`
- `fecha_recepcion`: `nullable|date`
- `estado`: `nullable|integer|in:0,6,7`
- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `nullable|string|max:15`

---

#### Reports

##### Introduction
The reports endpoints allow managing reports within the system. These endpoints provide functionality for creating, retrieving, updating, and deleting reports, along with managing associated operations and validation states.

##### Available Endpoints

###### 1. List Reports
- **URL:** `/informes`
- **Method:** `GET`
- **Description:** Retrieves a list of all reports in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "serie": "SER001",
      "codigo": 1,
      "estado_validacion": "P",
      "fecha_creacion": "2025-01-01",
      "fecha_envio": null,
      "fecha_validacion": null,
      "acreditado": "F",
      "final": "F",
      "visible": "T"
    },
    {
      "delegacion": "DEL002",
      "serie": "SER002",
      "codigo": 2,
      "estado_validacion": "V",
      "fecha_creacion": "2025-01-02",
      "fecha_envio": "2025-01-03",
      "fecha_validacion": "2025-01-03",
      "acreditado": "T",
      "final": "T",
      "visible": "F"
    }
  ]
  ```

###### 2. Retrieve Report Details
- **URL:** `/informes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific report.
- **Parameters:**
  - `codigo` (required): Identifier for the report.
  - `delegacion` (optional): Delegation code associated with the report.
  - `clave1` (optional): Series associated with the report.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "serie": "SER001",
    "codigo": 1,
    "estado_validacion": "P",
    "fecha_creacion": "2025-01-01",
    "fecha_envio": null,
    "fecha_validacion": null,
    "acreditado": "F",
    "final": "F",
    "visible": "T",
    "opiniones": "Pending validation",
    "observaciones": "No comments",
    "usuario_delegacion": "USR001",
    "usuario_codigo": "U001",
    "forma_envio_delegacion": "DEL001",
    "forma_envio_codigo": 1,
    "normativa_delegacion": "DEL001",
    "normativa_codigo": "N001",
    "firma_delegacion": "DEL001",
    "firma_codigo": 1
  }
  ```

###### 3. Create a Report
- **URL:** `/informes`
- **Method:** `POST`
- **Description:** Creates a new report in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "serie": "SER001",
    "codigo": 1,
    "estado_validacion": "P",
    "fecha_creacion": "2025-01-01",
    "acreditado": "T",
    "final": "F",
    "visible": "T",
    "opiniones": "Initial submission",
    "observaciones": "Awaiting review",
    "usuario_delegacion": "DEL001",
    "usuario_codigo": "U001",
    "operaciones": [
      {
        "delegacion": "DEL001",
        "serie": "SER001",
        "codigo": 123
      }
    ]
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "serie": "SER001",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Report
- **URL:** `/informes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific report.
- **Request Body:**
  ```json
  {
    "estado_validacion": "V",
    "fecha_envio": "2025-01-02",
    "fecha_validacion": "2025-01-03",
    "final": "T",
    "visible": "F",
    "observaciones": "Validation completed"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Report
- **URL:** `/informes/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific report from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the report.
  - `delegacion` (optional): Delegation code associated with the report.
  - `clave1` (optional): Series associated with the report.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

---

##### Available Fields for Reports
The following fields are supported for reports in the system:

| **Field**                     | **Type**   | **Description**                                            |
|-------------------------------|------------|------------------------------------------------------------|
| `delegacion`                  | `string`   | Delegation code for the report.                            |
| `serie`                       | `string`   | Series associated with the report.                         |
| `codigo`                      | `integer`  | Identifier for the report.                                 |
| `estado_validacion`           | `string`   | Validation (`P`: Pending, `V`: Validated, `R`: Rejected).  |
| `fecha_creacion`              | `date`     | Creation date of the report.                               |
| `fecha_envio`                 | `date`     | Sending date of the report.                                |
| `fecha_validacion`            | `date`     | Validation date of the report.                             |
| `acreditado`                  | `string`   | Indicates if the report is accredited (`T`, `F`).          |
| `final`                       | `string`   | Indicates if the report is final (`T`, `F`).               |
| `visible`                     | `string`   | Indicates if the report is visible (`T`, `F`).             |
| `opiniones`                   | `string`   | Opinions related to the report.                            |
| `observaciones`               | `string`   | Observations related to the report.                        |
| `usuario_delegacion`          | `string`   | Delegation code for the associated user.                   |
| `usuario_codigo`              | `string`   | Code for the associated user.                              |
| `forma_envio_delegacion`      | `string`   | Delegation code for the sending method.                    |
| `forma_envio_codigo`          | `integer`  | Code for the sending method.                               |
| `normativa_delegacion`        | `string`   | Delegation code for the normative.                         |
| `normativa_codigo`            | `string`   | Code for the normative.                                    |
| `firma_delegacion`            | `string`   | Delegation code for the signature.                         |
| `firma_codigo`                | `integer`  | Code for the signature.                                    |

---

##### Validation Rules for Reports
The following validation rules apply when creating or updating reports:

- `delegacion`: `nullable|string|max:10`
- `serie`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `estado_validacion`: `required|string|in:P,V,R|max:1`
- `fecha_creacion`: `nullable|date`
- `fecha_envio`: `nullable|date`
- `fecha_validacion`: `nullable|date`
- `acreditado`: `nullable|string|in:T,F|max:1`
- `final`: `nullable|string|in:T,F|max:1`
- `visible`: `nullable|string|in:T,F|max:1`
- `opiniones`: `nullable|string`
- `observaciones`: `nullable|string`
- `usuario_delegacion`: `nullable|string|max:10`
- `usuario_codigo`: `nullable|string|max:15`
- `forma_envio_delegacion`: `nullable|string|max:10`
- `forma_envio_codigo`: `nullable|integer`
- `normativa_delegacion`: `nullable|string|max:10`
- `normativa_codigo`: `nullable|string|max:20`
- `firma_delegacion`: `nullable|string|max:10`
- `firma_codigo`: `nullable|integer`
- `operaciones`: `required|array|min:1` (when creating)
  - **`operaciones.*.delegacion`**: `nullable|string|max:10`
  - **`operaciones.*.serie`**: `nullable|string|max:10`
  - **`operaciones.*.codigo`**: `required|integer`

---

#### Report Signatures

##### Introduction
The report signatures endpoints allow managing the signatures associated with reports in the system. These endpoints provide functionality for creating, retrieving, updating, and deleting signatures.

##### Available Endpoints

###### 1. List Signatures for a Report
- **URL:** `/informes-firmas/{codigo}/{delegacion?}/{clave1?}`
- **Method:** `GET`
- **Description:** Retrieves a list of signatures associated with a specific report.
- **Parameters:**
  - `codigo` (required): Identifier for the report.
  - `delegacion` (optional): Delegation code associated with the report.
  - `clave1` (optional): Series associated with the report.
- **Example Response:**
  ```json
  [
    {
      "informe_delegacion": "INF001",
      "informe_serie": "SER001",
      "informe_codigo": 1,
      "firma_delegacion": "TIF001",
      "firma_codigo": 101,
      "departamento_delegacion": "DEP001",
      "departamento_codigo": 201,
      "fecha_firma": "2025-01-01",
      "estado_firma": "T",
      "comentarios": "Approved",
      "usuario_delegacion": "USR001",
      "usuario_codigo": "U001"
    },
    {
      "informe_delegacion": "INF002",
      "informe_serie": "SER002",
      "informe_codigo": 2,
      "firma_delegacion": "TIF002",
      "firma_codigo": 102,
      "departamento_delegacion": "DEP002",
      "departamento_codigo": 202,
      "fecha_firma": "2025-01-02",
      "estado_firma": "F",
      "comentarios": "Rejected",
      "usuario_delegacion": "USR002",
      "usuario_codigo": "U002"
    }
  ]
  ```

###### 2. Retrieve Signature Details for a Report
- **URL:** `/informes-firmas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}/{clave5?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific signature associated with a report.
- **Parameters:**
  - `codigo` (required): Identifier for the report.
  - `delegacion` (optional): Delegation code associated with the report.
  - `clave1` (optional): Series associated with the report.
  - `clave2` (required): Identifier for the signature.
  - `clave3` (optional): Delegation code associated with the signature.
  - `clave4` (required): Code for the department.
  - `clave5` (optional): Delegation code for the department.
- **Example Response:**
  ```json
  {
    "informe_delegacion": "INF001",
    "informe_serie": "SER001",
    "informe_codigo": 1,
    "firma_delegacion": "TIF001",
    "firma_codigo": 101,
    "departamento_delegacion": "DEP001",
    "departamento_codigo": 201,
    "fecha_firma": "2025-01-01",
    "estado_firma": "T",
    "comentarios": "Approved",
    "usuario_delegacion": "USR001",
    "usuario_codigo": "U001"
  }
  ```

###### 3. Create a Signature for a Report
- **URL:** `/informes-firmas`
- **Method:** `POST`
- **Description:** Creates a new signature and associates it with a report.
- **Request Body:**
  ```json
  {
    "informe_delegacion": "INF001",
    "informe_serie": "SER001",
    "informe_codigo": 1,
    "firma_delegacion": "TIF001",
    "firma_codigo": 101,
    "departamento_delegacion": "DEP001",
    "departamento_codigo": 201,
    "fecha_firma": "2025-01-01",
    "estado_firma": "T",
    "comentarios": "Approved",
    "usuario_delegacion": "USR001",
    "usuario_codigo": "U001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
  }
  ```

###### 4. Update a Signature for a Report
- **URL:** `/informes-firmas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}/{clave5?}`
- **Method:** `PUT`
- **Description:** Updates the details of a specific signature associated with a report.
- **Request Body:**
  ```json
  {
    "estado_firma": "F",
    "comentarios": "Updated comments"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Signature from a Report
- **URL:** `/informes-firmas/{codigo}/{delegacion?}/{clave1?}/{clave2}/{clave3?}/{clave4}/{clave5?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific signature from a report.
- **Parameters:**
  - `codigo` (required): Identifier for the report.
  - `delegacion` (optional): Delegation code associated with the report.
  - `clave1` (optional): Series associated with the report.
  - `clave2` (required): Identifier for the signature.
  - `clave3` (optional): Delegation code associated with the signature.
  - `clave4` (required): Code for the department.
  - `clave5` (optional): Delegation code for the department.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Report Signatures
The following fields are supported for report signatures:

| **Field**                  | **Type**   | **Description**                                  |
|----------------------------|------------|--------------------------------------------------|
| `informe_delegacion`       | `string`   | Delegation code for the report.                  |
| `informe_serie`            | `string`   | Series associated with the report.               |
| `informe_codigo`           | `integer`  | Identifier for the report.                       |
| `firma_delegacion`         | `string`   | Delegation code for the signature.               |
| `firma_codigo`             | `integer`  | Identifier for the signature.                    |
| `departamento_delegacion`  | `string`   | Delegation code for the department.              |
| `departamento_codigo`      | `integer`  | Code for the department.                         |
| `fecha_firma`              | `date`     | Date the signature was created.                  |
| `estado_firma`             | `string`   | State (`T` = Approved, `F` = Rejected).          |
| `comentarios`              | `string`   | Comments related to the signature.               |
| `usuario_delegacion`       | `string`   | Delegation code for the user.                    |
| `usuario_codigo`           | `string`   | Identifier for the user.                         |

---

##### Validation Rules for Report Signatures
The following validation rules apply when creating or updating report signatures:

- `informe_delegacion`: `nullable|string|max:10`
- `informe_serie`: `nullable|string|max:10`
- `informe_codigo`: `nullable|integer`
- `firma_delegacion`: `nullable|string|max:10`
- `firma_codigo`: `nullable|integer`
- `departamento_delegacion`: `nullable|string|max:10`
- `departamento_codigo`: `nullable|integer`
- `fecha_firma`: `nullable|date`
- `estado_firma`: `nullable|string|in:T,F|max:1`
- `comentarios`: `nullable|string|max:255`
- `usuario_delegacion`: `nullable|string|max:10`
- `usuario_codigo`: `nullable|string|max:15`

---

#### Clients

##### Introduction
The clients endpoints provide functionality to manage client records in the system. These endpoints allow creating, retrieving, updating, and deleting client data.

##### Available Endpoints

###### 1. List Clients
- **URL:** `/clientes`
- **Method:** `GET`
- **Description:** Retrieves a list of all clients in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "CLI001",
      "nombre": "Client 1",
      "razon_social": "Client 1 Corp",
      "nif": "A12345678",
      "telefono": "123456789",
      "email": "client1@example.com"
    },
    {
      "delegacion": "DEL002",
      "codigo": "CLI002",
      "nombre": "Client 2",
      "razon_social": "Client 2 Corp",
      "nif": "B87654321",
      "telefono": "987654321",
      "email": "client2@example.com"
    }
  ]
  ```

###### 2. Retrieve Client Details
- **URL:** `/clientes/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific client.
- **Parameters:**
  - `codigo` (required): Identifier for the client.
  - `delegacion` (optional): Delegation code associated with the client.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "CLI001",
    "nombre": "Client 1",
    "razon_social": "Client 1 Corp",
    "actividad": "Consulting",
    "nif": "A12345678",
    "direccion_1": "123 Main Street",
    "poblacion_1": "City 1",
    "provincia_1": "Province 1",
    "codigo_postal_1": "12345",
    "pais_1": "ESP",
    "telefono": "123456789",
    "email": "client1@example.com",
    "observaciones": "Important client"
  }
  ```

###### 3. Create a Client
- **URL:** `/clientes`
- **Method:** `POST`
- **Description:** Creates a new client in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "CLI001",
    "nombre": "Client 1",
    "razon_social": "Client 1 Corp",
    "nif": "A12345678",
    "direccion_1": "123 Main Street",
    "poblacion_1": "City 1",
    "provincia_1": "Province 1",
    "codigo_postal_1": "12345",
    "pais_1": "ESP",
    "telefono": "123456789",
    "email": "client1@example.com"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "CLI001",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Client
- **URL:** `/clientes/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific client.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Client Name",
    "razon_social": "Updated Client Corp",
    "telefono": "111222333",
    "email": "updatedclient@example.com"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Client
- **URL:** `/clientes/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific client from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the client.
  - `delegacion` (optional): Delegation code associated with the client.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Clients
The following fields are supported for clients:

| **Field**                           | **Type**   | **Description**                                         |
|-------------------------------------|------------|---------------------------------------------------------|
| `delegacion`                        | `string`   | Delegation code for the client.                         |
| `codigo`                            | `string`   | Identifier for the client.                              |
| `nombre`                            | `string`   | Name of the client.                                     |
| `razon_social`                      | `string`   | Corporate name of the client.                           |
| `actividad`                         | `string`   | Activity or business type of the client.                |
| `nif`                               | `string`   | Tax identification number of the client.                |
| `direccion_1`                       | `string`   | Primary address of the client.                          |
| `poblacion_1`                       | `string`   | City of the primary address.                            |
| `provincia_1`                       | `string`   | Province of the primary address.                        |
| `codigo_postal_1`                   | `string`   | Postal code of the primary address.                     |
| `pais_1`                            | `string`   | Country of the primary address.                         |
| `es_facturacion_1`                  | `string`   | Indicates if this is the billing address.               |
| `direccion_2`                       | `string`   | Secondary address of the client.                        |
| `poblacion_2`                       | `string`   | City of the secondary address.                          |
| `provincia_2`                       | `string`   | Province of the secondary address.                      |
| `codigo_postal_2`                   | `string`   | Postal code of the secondary address.                   |
| `pais_2`                            | `string`   | Country of the secondary address.                       |
| `es_facturacion_2`                  | `string`   | Indicates if this is the billing address.               |
| `direccion_3`                       | `string`   | Tertiary address of the client.                         |
| `poblacion_3`                       | `string`   | City of the tertiary address.                           |
| `provincia_3`                       | `string`   | Province of the tertiary address.                       |
| `codigo_postal_3`                   | `string`   | Postal code of the tertiary address.                    |
| `pais_3`                            | `string`   | Country of the tertiary address.                        |
| `es_facturacion_3`                  | `string`   | Indicates if this is the billing address.               |
| `telefono`                          | `string`   | Primary phone number of the client.                     |
| `movil`                             | `string`   | Mobile phone number of the client.                      |
| `fax`                               | `string`   | Fax number of the client.                               |
| `persona_contacto`                  | `string`   | Main contact person for the client.                     |
| `email`                             | `string`   | Primary email address of the client.                    |
| `web`                               | `string`   | Client's website URL.                                   |
| `fecha_alta`                        | `date`     | Date when the client was registered.                    |
| `fecha_baja`                        | `date`     | Date when the client was marked inactive.               |
| `observaciones`                     | `string`   | Observations or notes about the client.                 |
| `notas_facturacion`                 | `string`   | Notes related to billing.                               |
| `modo_facturacion`                  | `string`   | Billing mode (`C`: Cash, `P`: Prepaid, `N`: Net terms). |
| `forma_pago`                        | `string`   | Payment method for the client.                          |
| `numero_cuenta`                     | `string`   | Bank account number of the client.                      |
| `tipo_persona`                      | `string`   | Type of person (`F`: Individual, `J`: Company).         |
| `residencia`                        | `string`   | Residence type (`E`: EU, `R`: Non-EU, `U`: Unknown).    |
| `tipo_impuesto_1`                   | `string`   | Tax type 1.                                             |
| `valor_impuesto_1`                  | `string`   | Value of tax type 1.                                    |
| `tipo_impuesto_2`                   | `string`   | Tax type 2.                                             |
| `valor_impuesto_2`                  | `string`   | Value of tax type 2.                                    |
| `descuento`                         | `string`   | Discount applied to the client.                         |
| `es_baja`                           | `string`   | Indicates if the client is inactive.                    |
| `dias_vencimiento_facturas`         | `integer`  | Number of days until invoice due.                       |
| `dias_pago_facturas`                | `integer`  | Number of days for invoice payment.                     |
| `dias_vencimiento_presupuestos`     | `integer`  | Number of days until budget expiration.                 |
| `cliente_principal_delegacion`      | `string`   | Delegation code of the main client.                     |
| `cliente_principal_codigo`          | `string`   | Code of the main client.                                |
| `forma_envio_delegacion`            | `string`   | Delegation code for the delivery method.                |
| `forma_envio_codigo`                | `integer`  | Code for the delivery method.                           |
| `tipo_cliente_delegacion`           | `string`   | Delegation code for the client type.                    |
| `tipo_cliente_codigo`               | `integer`  | Code for the client type.                               |
| `telefono_2`                        | `string`   | Secondary phone number of the client.                   |
| `movil_2`                           | `string`   | Secondary mobile phone number of the client.            |
| `fax_2`                             | `string`   | Secondary fax number of the client.                     |
| `persona_contacto_2`                | `string`   | Secondary contact person for the client.                |
| `email_2`                           | `string`   | Secondary email address of the client.                  |
| `web_2`                             | `string`   | Secondary website URL of the client.                    |
| `telefono_3`                        | `string`   | Tertiary phone number of the client.                    |
| `movil_3`                           | `string`   | Tertiary mobile phone number of the client.             |
| `fax_3`                             | `string`   | Tertiary fax number of the client.                      |
| `persona_contacto_3`                | `string`   | Tertiary contact person for the client.                 |
| `email_3`                           | `string`   | Tertiary email address of the client.                   |
| `web_3`                             | `string`   | Tertiary website URL of the client.                     |
| `informacion_adicional`             | `string`   | Additional information about the client.                |
| `otros_datos`                       | `string`   | Other relevant data about the client.                   |
| `proyecto`                          | `string`   | Project associated with the client.                     |
| `tarifa_delegacion`                 | `string`   | Delegation code for the client's rate.                  |
| `tarifa_codigo`                     | `integer`  | Code for the client's rate.                             |
| `cliente_igeo`                      | `string`   | IGEO client identifier.                                 |

---

##### Validation Rules for Clients
The following validation rules apply when creating or updating clients:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:15`
- `nombre`: `nullable|string|max:255`
- `razon_social`: `nullable|string|max:255`
- `actividad`: `nullable|string|max:100`
- `nif`: `nullable|string|max:15`
- `direccion_1`: `nullable|string|max:255`
- `poblacion_1`: `nullable|string|max:100`
- `provincia_1`: `nullable|string|max:100`
- `codigo_postal_1`: `nullable|string|max:10`
- `pais_1`: `nullable|string|max:3`
- `es_facturacion_1`: `nullable|string|in:T,F|max:1`
- `direccion_2`: `nullable|string|max:255`
- `poblacion_2`: `nullable|string|max:100`
- `provincia_2`: `nullable|string|max:100`
- `codigo_postal_2`: `nullable|string|max:10`
- `pais_2`: `nullable|string|max:3`
- `es_facturacion_2`: `nullable|string|in:T,F|max:1`
- `direccion_3`: `nullable|string|max:255`
- `poblacion_3`: `nullable|string|max:100`
- `provincia_3`: `nullable|string|max:100`
- `codigo_postal_3`: `nullable|string|max:10`
- `pais_3`: `nullable|string|max:3`
- `es_facturacion_3`: `nullable|string|in:T,F|max:1`
- `telefono`: `nullable|string|max:40`
- `movil`: `nullable|string|max:40`
- `fax`: `nullable|string|max:40`
- `persona_contacto`: `nullable|string|max:255`
- `email`: `nullable|string`
- `web`: `nullable|string|max:100`
- `es_contacto_laboratorio`: `nullable|string|in:T,F|max:1`
- `es_contacto_administracion`: `nullable|string|in:T,F|max:1`
- `fecha_alta`: `nullable|date`
- `fecha_baja`: `nullable|date`
- `observaciones`: `nullable|string`
- `notas_facturacion`: `nullable|string`
- `modo_facturacion`: `nullable|string|in:C,P,N|max:1`
- `forma_pago`: `nullable|string|max:100`
- `numero_cuenta`: `nullable|string|max:50`
- `tipo_persona`: `nullable|string|in:F,J|max:1`
- `residencia`: `nullable|string|in:E,R,U|max:1`
- `tipo_impuesto_1`: `nullable|string|max:10`
- `valor_impuesto_1`: `nullable|string|max:10`
- `tipo_impuesto_2`: `nullable|string|max:10`
- `valor_impuesto_2`: `nullable|string|max:10`
- `descuento`: `nullable|string|max:10`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `dias_vencimiento_facturas`: `nullable|integer`
- `dias_pago_facturas`: `nullable|integer`
- `dias_vencimiento_presupuestos`: `nullable|integer`
- `cliente_principal_delegacion`: `nullable|string|max:10`
- `cliente_principal_codigo`: `nullable|string|max:15`
- `forma_envio_delegacion`: `nullable|string|max:10`
- `forma_envio_codigo`: `nullable|integer`
- `tipo_cliente_delegacion`: `nullable|string|max:10`
- `tipo_cliente_codigo`: `nullable|integer`
- `telefono_2`: `nullable|string|max:40`
- `movil_2`: `nullable|string|max:40`
- `fax_2`: `nullable|string|max:40`
- `persona_contacto_2`: `nullable|string|max:255`
- `email_2`: `nullable|string`
- `web_2`: `nullable|string|max:100`
- `es_contacto_laboratorio_2`: `nullable|string|in:T,F|max:1`
- `es_contacto_administracion_2`: `nullable|string|in:T,F|max:1`
- `telefono_3`: `nullable|string|max:40`
- `movil_3`: `nullable|string|max:40`
- `fax_3`: `nullable|string|max:40`
- `persona_contacto_3`: `nullable|string|max:255`
- `email_3`: `nullable|string`
- `web_3`: `nullable|string|max:100`
- `es_contacto_laboratorio_3`: `nullable|string|in:T,F|max:1`
- `es_contacto_administracion_3`: `nullable|string|in:T,F|max:1`
- `informacion_adicional`: `nullable|string|max:255`
- `otros_datos`: `nullable|string|max:255`
- `proyecto`: `nullable|string|max:255`
- `tarifa_delegacion`: `nullable|string|max:10`
- `tarifa_codigo`: `nullable|integer`
- `cliente_igeo`: `nullable|string|max:20`

#### Sampling Points

##### Introduction
The sampling points endpoints allow managing the sampling points associated with clients in the system. These endpoints provide functionality for creating, retrieving, updating, and deleting sampling point records.

##### Available Endpoints

###### 1. List Sampling Points
- **URL:** `/clientes-puntos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of all sampling points associated with a specific client.
- **Parameters:**
  - `codigo` (required): Identifier for the client.
  - `delegacion` (optional): Delegation code associated with the client.
- **Example Response:**
  ```json
  [
    {
      "cliente_delegacion": "DEL001",
      "cliente_codigo": "CLI001",
      "codigo": 1,
      "descripcion": "Sampling Point 1",
      "referencia": "REF001",
      "municipio": "Municipality 1",
      "latitud": "40.123456",
      "longitud": "-3.654321"
    },
    {
      "cliente_delegacion": "DEL001",
      "cliente_codigo": "CLI001",
      "codigo": 2,
      "descripcion": "Sampling Point 2",
      "referencia": "REF002",
      "municipio": "Municipality 2",
      "latitud": "41.123456",
      "longitud": "-4.654321"
    }
  ]
  ```

###### 2. Retrieve Sampling Point Details
- **URL:** `/clientes-puntos/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific sampling point.
- **Parameters:**
  - `codigo` (required): Identifier for the client.
  - `delegacion` (optional): Delegation code associated with the client.
  - `clave1` (required): Identifier for the sampling point.
- **Example Response:**
  ```json
  {
    "cliente_delegacion": "DEL001",
    "cliente_codigo": "CLI001",
    "codigo": 1,
    "descripcion": "Sampling Point 1",
    "referencia": "REF001",
    "municipio": "Municipality 1",
    "latitud": "40.123456",
    "longitud": "-3.654321",
    "altitud": "100",
    "proyecto": "Project A",
    "instrumento_ambiental": "Instrument X"
  }
  ```

###### 3. Create a Sampling Point
- **URL:** `/clientes-puntos`
- **Method:** `POST`
- **Description:** Creates a new sampling point for a client.
- **Request Body:**
  ```json
  {
    "cliente_delegacion": "DEL001",
    "cliente_codigo": "CLI001",
    "descripcion": "Sampling Point 1",
    "referencia": "REF001",
    "municipio": "Municipality 1",
    "latitud": "40.123456",
    "longitud": "-3.654321",
    "altitud": "100",
    "proyecto": "Project A",
    "instrumento_ambiental": "Instrument X"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
  }
  ```

###### 4. Update a Sampling Point
- **URL:** `/clientes-puntos/{codigo}/{delegacion?}/{clave1}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific sampling point.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Sampling Point",
    "referencia": "Updated Reference",
    "municipio": "Updated Municipality",
    "latitud": "41.123456",
    "longitud": "-4.654321"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Sampling Point
- **URL:** `/clientes-puntos/{codigo}/{delegacion?}/{clave1}`
- **Method:** `DELETE`
- **Description:** Deletes a specific sampling point from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the client.
  - `delegacion` (optional): Delegation code associated with the client.
  - `clave1` (required): Identifier for the sampling point.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Sampling Points
The following fields are supported for sampling points:

| **Field**                 | **Type**   | **Description**                                  |
|---------------------------|------------|--------------------------------------------------|
| `cliente_delegacion`      | `string`   | Delegation code for the client.                  |
| `cliente_codigo`          | `string`   | Identifier for the client.                       |
| `codigo`                  | `integer`  | Identifier for the sampling point.               |
| `descripcion`             | `string`   | Description of the sampling point.               |
| `referencia`              | `string`   | Reference for the sampling point.                |
| `es_baja`                 | `string`   | Indicates if the sampling point is inactive.     |
| `es_categoria`            | `string`   | Indicates if the sampling point is categorized.  |
| `codigo_punto`            | `integer`  | Unique code for the sampling point.              |
| `tipo_punto`              | `integer`  | Type of the sampling point.                      |
| `codigo_identificativo`   | `integer`  | Unique identifier for the sampling point.        |
| `ubicacion`               | `string`   | Location of the sampling point.                  |
| `codigo_msc`              | `integer`  | MSC code for the sampling point.                 |
| `municipio`               | `string`   | Municipality where the sampling point is located.|
| `latitud`                 | `string`   | Latitude coordinates.                            |
| `longitud`                | `string`   | Longitude coordinates.                           |
| `altitud`                 | `string`   | Altitude of the sampling point.                  |
| `error_gps`               | `string`   | GPS error margin.                                |
| `proyecto`                | `string`   | Project associated with the sampling point.      |
| `actividad`               | `string`   | Activity related to the sampling point.          |
| `instrumento_ambiental`   | `string`   | Environmental instrument used.                   |
| `minimo_control`          | `integer`  | Minimum control value.                           |
| `minimo_completos`        | `integer`  | Minimum completed samples required.              |
| `minimo_muestras`         | `integer`  | Minimum number of samples required.              |
| `categoria`               | `integer`  | Category code for the sampling point.            |

---

##### Validation Rules for Sampling Points
The following validation rules apply when creating or updating sampling points:

- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `nullable|string|max:15`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:255`
- `referencia`: `nullable|string|max:100`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `es_categoria`: `nullable|string|in:T,F|max:1`
- `codigo_punto`: `nullable|integer`
- `tipo_punto`: `nullable|integer`
- `codigo_identificativo`: `nullable|integer`
- `ubicacion`: `nullable|string`
- `codigo_msc`: `nullable|integer`
- `municipio`: `nullable|string|max:100`
- `latitud`: `nullable|string|max:100`
- `longitud`: `nullable|string|max:100`
- `altitud`: `nullable|string|max:100`
- `error_gps`: `nullable|string|max:100`
- `proyecto`: `nullable|string|max:100`
- `actividad`: `nullable|string|max:100`
- `instrumento_ambiental`: `nullable|string|max:100`
- `minimo_control`: `nullable|integer`
- `minimo_completos`: `nullable|integer`
- `minimo_muestras`: `nullable|integer`
- `categoria`: `nullable|integer`

#### Client Types

##### Introduction
The client types endpoints allow managing the various types of clients in the system. These endpoints provide functionality for creating, retrieving, updating, and deleting client type records.

##### Available Endpoints

###### 1. List Client Types
- **URL:** `/tipos-cliente`
- **Method:** `GET`
- **Description:** Retrieves a list of all client types in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Type A",
      "es_baja": "F"
    },
    {
      "delegacion": "DEL002",
      "codigo": 2,
      "descripcion": "Type B",
      "es_baja": "T"
    }
  ]
  ```

###### 2. Retrieve Client Type Details
- **URL:** `/tipos-cliente/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific client type.
- **Parameters:**
  - `codigo` (required): Identifier for the client type.
  - `delegacion` (optional): Delegation code associated with the client type.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Type A",
    "es_baja": "F"
  }
  ```

###### 3. Create a Client Type
- **URL:** `/tipos-cliente`
- **Method:** `POST`
- **Description:** Creates a new client type in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Type A",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Client Type
- **URL:** `/tipos-cliente/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific client type.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Type A",
    "es_baja": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Client Type
- **URL:** `/tipos-cliente/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific client type from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the client type.
  - `delegacion` (optional): Delegation code associated with the client type.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Client Types
The following fields are supported for client types:

| **Field**        | **Type**   | **Description**                            |
|------------------|------------|--------------------------------------------|
| `delegacion`     | `string`   | Delegation code associated with the type.  |
| `codigo`         | `integer`  | Unique identifier for the client type.     |
| `descripcion`    | `string`   | Description or name of the client type.    |
| `es_baja`        | `string`   | Indicates if the client type is inactive.  |

---

##### Validation Rules for Client Types
The following validation rules apply when creating or updating client types:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:50`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Rates

##### Introduction
The rates endpoints allow managing the various rate records in the system. These endpoints provide functionality for creating, retrieving, updating, and deleting rate records.

##### Available Endpoints

###### 1. List Rates
- **URL:** `/tarifas`
- **Method:** `GET`
- **Description:** Retrieves a list of all rates in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Rate A",
      "es_baja": "F",
      "orden": 1
    },
    {
      "delegacion": "DEL002",
      "codigo": 2,
      "descripcion": "Rate B",
      "es_baja": "T",
      "orden": 2
    }
  ]
  ```

###### 2. Retrieve Rate Details
- **URL:** `/tarifas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific rate.
- **Parameters:**
  - `codigo` (required): Identifier for the rate.
  - `delegacion` (optional): Delegation code associated with the rate.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Rate A",
    "es_baja": "F",
    "orden": 1
  }
  ```

###### 3. Create a Rate
- **URL:** `/tarifas`
- **Method:** `POST`
- **Description:** Creates a new rate in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Rate A",
    "es_baja": "F",
    "orden": 1
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Rate
- **URL:** `/tarifas/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific rate.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Rate A",
    "es_baja": "T",
    "orden": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Rate
- **URL:** `/tarifas/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific rate from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the rate.
  - `delegacion` (optional): Delegation code associated with the rate.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Rates
The following fields are supported for rates:

| **Field**        | **Type**   | **Description**                                    |
|------------------|------------|----------------------------------------------------|
| `delegacion`     | `string`   | Delegation code associated with the rate.          |
| `codigo`         | `integer`  | Unique identifier for the rate.                    |
| `descripcion`    | `string`   | Description or name of the rate.                   |
| `es_baja`        | `string`   | Indicates if the rate is inactive.                 |
| `orden`          | `integer`  | Order or priority of the rate in the system.       |

---

##### Validation Rules for Rates
The following validation rules apply when creating or updating rates:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:50`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `orden`: `nullable|integer`

#### Shipping Methods

##### Introduction
The shipping methods endpoints allow managing the various shipping methods in the system. These endpoints provide functionality for creating, retrieving, updating, and deleting shipping method records.

##### Available Endpoints

###### 1. List Shipping Methods
- **URL:** `/formas-envio`
- **Method:** `GET`
- **Description:** Retrieves a list of all shipping methods in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Standard Shipping",
      "especial": "N",
      "es_baja": "F"
    },
    {
      "delegacion": "DEL002",
      "codigo": 2,
      "descripcion": "Express Shipping",
      "especial": "E",
      "es_baja": "T"
    }
  ]
  ```

###### 2. Retrieve Shipping Method Details
- **URL:** `/formas-envio/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific shipping method.
- **Parameters:**
  - `codigo` (required): Identifier for the shipping method.
  - `delegacion` (optional): Delegation code associated with the shipping method.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Standard Shipping",
    "especial": "N",
    "es_baja": "F"
  }
  ```

###### 3. Create a Shipping Method
- **URL:** `/formas-envio`
- **Method:** `POST`
- **Description:** Creates a new shipping method in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Standard Shipping",
    "especial": "N",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Shipping Method
- **URL:** `/formas-envio/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific shipping method.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Standard Shipping",
    "especial": "E",
    "es_baja": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Shipping Method
- **URL:** `/formas-envio/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific shipping method from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the shipping method.
  - `delegacion` (optional): Delegation code associated with the shipping method.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Shipping Methods
The following fields are supported for shipping methods:

| **Field**        | **Type**   | **Description**                                      |
|------------------|------------|------------------------------------------------------|
| `delegacion`     | `string`   | Delegation code associated with the shipping method. |
| `codigo`         | `integer`  | Unique identifier for the shipping method.           |
| `descripcion`    | `string`   | Description or name of the shipping method.          |
| `especial`       | `string`   | Indicates if the method is special (N, E, P).        |
| `es_baja`        | `string`   | Indicates if the shipping method is inactive.        |

---

##### Validation Rules for Shipping Methods
The following validation rules apply when creating or updating shipping methods:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:50`
- `especial`: `nullable|string|in:N,E,P|max:1`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Providers

##### Introduction
The providers endpoints allow managing providers in the system, including their details such as address, contact information, and other relevant attributes.

##### Available Endpoints

###### 1. List Providers
- **URL:** `/proveedores`
- **Method:** `GET`
- **Description:** Retrieves a list of all providers in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "PRO001",
      "nombre": "Provider A",
      "razon_social": "Provider A Corp",
      "telefono": "123456789",
      "email": "providerA@example.com",
      "es_baja": "F"
    },
    {
      "delegacion": "DEL002",
      "codigo": "PRO002",
      "nombre": "Provider B",
      "razon_social": "Provider B Inc",
      "telefono": "987654321",
      "email": "providerB@example.com",
      "es_baja": "T"
    }
  ]
  ```

###### 2. Retrieve Provider Details
- **URL:** `/proveedores/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific provider.
- **Parameters:**
  - `codigo` (required): Identifier for the provider.
  - `delegacion` (optional): Delegation code associated with the provider.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "PRO001",
    "nombre": "Provider A",
    "razon_social": "Provider A Corp",
    "direccion": "123 Provider Street",
    "telefono": "123456789",
    "email": "providerA@example.com",
    "productos_suministrados": "Chemicals, Equipment",
    "es_baja": "F"
  }
  ```

###### 3. Create a Provider
- **URL:** `/proveedores`
- **Method:** `POST`
- **Description:** Creates a new provider in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "PRO001",
    "nombre": "Provider A",
    "razon_social": "Provider A Corp",
    "direccion": "123 Provider Street",
    "telefono": "123456789",
    "email": "providerA@example.com",
    "productos_suministrados": "Chemicals, Equipment",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "PRO001",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Provider
- **URL:** `/proveedores/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific provider.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Provider A",
    "productos_suministrados": "Updated Chemicals",
    "es_baja": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Provider
- **URL:** `/proveedores/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific provider from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the provider.
  - `delegacion` (optional): Delegation code associated with the provider.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Providers
The following fields are supported for providers:

| **Field**                      | **Type**   | **Description**                                                  |
|--------------------------------|------------|------------------------------------------------------------------|
| `delegacion`                   | `string`   | Delegation code associated with the provider.                    |
| `codigo`                       | `string`   | Unique identifier for the provider.                              |
| `nombre`                       | `string`   | Name of the provider.                                            |
| `razon_social`                 | `string`   | Corporate name of the provider.                                  |
| `direccion`                    | `string`   | Address of the provider.                                         |
| `poblacion`                    | `string`   | City where the provider is located.                              |
| `provincia`                    | `string`   | Province where the provider is located.                          |
| `codigo_postal`                | `string`   | Postal code of the provider's address.                           |
| `telefono`                     | `string`   | Contact phone number of the provider.                            |
| `movil`                        | `string`   | Mobile phone number of the provider.                             |
| `fax`                          | `string`   | Fax number of the provider.                                      |
| `persona_contacto`             | `string`   | Contact person for the provider.                                 |
| `nif`                          | `string`   | Tax identification number of the provider.                       |
| `email`                        | `string`   | Email address of the provider.                                   |
| `web`                          | `string`   | Website of the provider.                                         |
| `fecha_alta`                   | `date`     | Registration date of the provider.                               |
| `fecha_baja`                   | `date`     | Deregistration date of the provider, if applicable.              |
| `es_proveedor_aceptado`        | `string`   | Indicates if the provider is accepted (`T`, `F`)                 |
| `productos_suministrados`      | `string`   | List of products supplied by the provider.                       |
| `plazo_entrega`                | `string`   | Delivery terms of the provider.                                  |
| `pedido_minimo`                | `string`   | Minimum order required by the provider.                          |
| `observaciones`                | `string`   | Additional notes or comments about the provider.                 |
| `es_laboratorio_subcontratado` | `string`   | Indicates if the provider is a subcontracted (`T`, `F`).         |
| `es_baja`                      | `string`   | Indicates if the provider is inactive (`T`, `F`).                |
| `tipo_evaluacion_delegacion`   | `string`   | Delegation code for the evaluation type associated.              |
| `tipo_evaluacion_codigo`       | `integer`  | Code for the evaluation type associated with the provider.       |

---

##### Validation Rules for Providers
The following validation rules apply when creating or updating providers:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:15`
- `nombre`: `nullable|string|max:255`
- `razon_social`: `nullable|string|max:255`
- `direccion`: `nullable|string|max:255`
- `poblacion`: `nullable|string|max:100`
- `provincia`: `nullable|string|max:100`
- `codigo_postal`: `nullable|string|max:10`
- `telefono`: `nullable|string|max:40`
- `movil`: `nullable|string|max:20`
- `fax`: `nullable|string|max:20`
- `persona_contacto`: `nullable|string|max:50`
- `nif`: `nullable|string|max:15`
- `email`: `nullable|string|max:100`
- `web`: `nullable|string|max:100`
- `fecha_alta`: `nullable|date`
- `fecha_baja`: `nullable|date`
- `es_proveedor_aceptado`: `nullable|string|in:T,F|max:1`
- `productos_suministrados`: `nullable|string`
- `plazo_entrega`: `nullable|string|max:50`
- `pedido_minimo`: `nullable|string|max:50`
- `observaciones`: `nullable|string`
- `es_laboratorio_subcontratado`: `nullable|string|in:T,F|max:1`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `tipo_evaluacion_delegacion`: `nullable|string|max:10`
- `tipo_evaluacion_codigo`: `nullable|integer`

---

#### Supplier Products

##### Introduction
The supplier products endpoints allow managing the products supplied by providers, including their references and pricing.

##### Available Endpoints

###### 1. List Supplier Products
- **URL:** `/proveedores-productos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of products supplied by a specific provider.
- **Parameters:**
  - `codigo` (required): Identifier for the supplier.
  - `delegacion` (optional): Delegation code for the supplier.
- **Example Response:**
  ```json
  [
    {
      "proveedor_delegacion": "DEL001",
      "proveedor_codigo": "PRO001",
      "producto_delegacion": "DEL002",
      "producto_codigo": "PRD001",
      "referencia": "REF001",
      "precio": 100.50
    },
    {
      "proveedor_delegacion": "DEL001",
      "proveedor_codigo": "PRO001",
      "producto_delegacion": "DEL002",
      "producto_codigo": "PRD002",
      "referencia": "REF002",
      "precio": 200.75
    }
  ]
  ```

###### 2. Retrieve Supplier Product Details
- **URL:** `/proveedores-productos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific product supplied by a provider.
- **Parameters:**
  - `codigo` (required): Identifier for the supplier.
  - `delegacion` (optional): Delegation code for the supplier.
  - `clave1` (required): Identifier for the product.
  - `clave2` (optional): Delegation code for the product.
- **Example Response:**
  ```json
  {
    "proveedor_delegacion": "DEL001",
    "proveedor_codigo": "PRO001",
    "producto_delegacion": "DEL002",
    "producto_codigo": "PRD001",
    "referencia": "REF001",
    "precio": 100.50
  }
  ```

###### 3. Create a Supplier Product
- **URL:** `/proveedores-productos`
- **Method:** `POST`
- **Description:** Creates a new supplier product in the system.
- **Request Body:**
  ```json
  {
    "proveedor_delegacion": "DEL001",
    "proveedor_codigo": "PRO001",
    "producto_delegacion": "DEL002",
    "producto_codigo": "PRD001",
    "referencia": "REF001",
    "precio": 100.50
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "proveedor_codigo": "PRO001",
      "producto_codigo": "PRD001"
    }
  }
  ```

###### 4. Update a Supplier Product
- **URL:** `/proveedores-productos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific supplier product.
- **Request Body:**
  ```json
  {
    "referencia": "Updated Reference",
    "precio": 150.75
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Supplier Product
- **URL:** `/proveedores-productos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific supplier product from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the supplier.
  - `delegacion` (optional): Delegation code for the supplier.
  - `clave1` (required): Identifier for the product.
  - `clave2` (optional): Delegation code for the product.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Supplier Products
The following fields are supported for supplier products:

| **Field**               | **Type**   | **Description**                                                  |
|-------------------------|------------|------------------------------------------------------------------|
| `proveedor_delegacion`  | `string`   | Delegation code associated with the supplier.                    |
| `proveedor_codigo`      | `string`   | Unique identifier for the supplier.                              |
| `producto_delegacion`   | `string`   | Delegation code associated with the product.                     |
| `producto_codigo`       | `string`   | Unique identifier for the product.                               |
| `referencia`            | `string`   | Reference code or identifier for the product.                    |
| `precio`                | `numeric`  | Price of the product supplied by the supplier.                   |

---

##### Validation Rules for Supplier Products
The following validation rules apply when creating or updating supplier products:

- `proveedor_delegacion`: `nullable|string|max:10`
- `proveedor_codigo`: `nullable|string|max:15`
- `producto_delegacion`: `nullable|string|max:10`
- `producto_codigo`: `nullable|string|max:15`
- `referencia`: `nullable|string|max:30`
- `precio`: `nullable|numeric|min:0`

---

#### Evaluation Types

##### Introduction
The evaluation types endpoints manage the types of evaluations associated with suppliers, allowing for CRUD operations on these entities.

##### Available Endpoints

###### 1. List Evaluation Types
- **URL:** `/tipos-evaluacion`
- **Method:** `GET`
- **Description:** Retrieves a list of all evaluation types.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Quality Evaluation",
      "es_baja": "F"
    },
    {
      "delegacion": "DEL002",
      "codigo": 2,
      "descripcion": "Performance Evaluation",
      "es_baja": "F"
    }
  ]
  ```

###### 2. Retrieve Evaluation Type Details
- **URL:** `/tipos-evaluacion/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific evaluation type.
- **Parameters:**
  - `codigo` (required): Identifier for the evaluation type.
  - `delegacion` (optional): Delegation code for the evaluation type.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Quality Evaluation",
    "es_baja": "F"
  }
  ```

###### 3. Create an Evaluation Type
- **URL:** `/tipos-evaluacion`
- **Method:** `POST`
- **Description:** Creates a new evaluation type in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 3,
    "descripcion": "Environmental Evaluation",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 3
    }
  }
  ```

###### 4. Update an Evaluation Type
- **URL:** `/tipos-evaluacion/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific evaluation type.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Description",
    "es_baja": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Evaluation Type
- **URL:** `/tipos-evaluacion/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific evaluation type from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the evaluation type.
  - `delegacion` (optional): Delegation code for the evaluation type.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Evaluation Types
The following fields are supported for evaluation types:

| **Field**         | **Type**   | **Description**                                          |
|-------------------|------------|----------------------------------------------------------|
| `delegacion`      | `string`   | Delegation code for the evaluation type.                 |
| `codigo`          | `integer`  | Unique identifier for the evaluation type.               |
| `descripcion`     | `string`   | Description of the evaluation type.                      |
| `es_baja`         | `string`   | Indicates if the evaluation type is inactive (`T`, `F`). |

---

##### Validation Rules for Evaluation Types
The following validation rules apply when creating or updating evaluation types:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:100`
- `es_baja`: `nullable|string|in:T,F|max:1`

---

#### Products

##### Introduction
The products endpoints allow managing the information related to products in the system. These endpoints support CRUD operations for products, including details such as stock levels, prices, and suppliers.

##### Available Endpoints

###### 1. List Products
- **URL:** `/productos`
- **Method:** `GET`
- **Description:** Retrieves a list of all products in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "PRD001",
      "descripcion": "Product 1",
      "precio": 10.50,
      "stock_minimo": 5,
      "stock_maximo": 50,
      "existencias_unidades": 20,
      "es_baja": "F"
    },
    {
      "delegacion": "DEL002",
      "codigo": "PRD002",
      "descripcion": "Product 2",
      "precio": 15.75,
      "stock_minimo": 10,
      "stock_maximo": 100,
      "existencias_unidades": 60,
      "es_baja": "F"
    }
  ]
  ```

###### 2. Retrieve Product Details
- **URL:** `/productos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific product.
- **Parameters:**
  - `codigo` (required): Identifier for the product.
  - `delegacion` (optional): Delegation code for the product.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "PRD001",
    "descripcion": "Product 1",
    "marca": "Brand A",
    "modelo": "Model X",
    "fabricante": "Manufacturer A",
    "precio": 10.50,
    "stock_minimo": 5,
    "stock_maximo": 50,
    "existencias_unidades": 20,
    "observaciones": "Stock is sufficient",
    "es_baja": "F"
  }
  ```

###### 3. Create a Product
- **URL:** `/productos`
- **Method:** `POST`
- **Description:** Creates a new product in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "PRD003",
    "descripcion": "Product 3",
    "marca": "Brand B",
    "modelo": "Model Y",
    "fabricante": "Manufacturer B",
    "precio": 20.00,
    "stock_minimo": 10,
    "stock_maximo": 100,
    "existencias_unidades": 30,
    "observaciones": "New product added",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "PRD003"
    }
  }
  ```

###### 4. Update a Product
- **URL:** `/productos/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific product.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Product Description",
    "precio": 25.00,
    "stock_minimo": 15,
    "stock_maximo": 120,
    "existencias_unidades": 50,
    "observaciones": "Updated product information"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Product
- **URL:** `/productos/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific product from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the product.
  - `delegacion` (optional): Delegation code for the product.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Products
The following fields are supported for products:

| **Field**                   | **Type**   | **Description**                                                              |
|-----------------------------|------------|------------------------------------------------------------------------------|
| `delegacion`                | `string`   | Delegation code for the product.                                             |
| `codigo`                    | `string`   | Unique identifier for the product.                                           |
| `descripcion`               | `string`   | Description of the product.                                                  |
| `marca`                     | `string`   | Brand of the product.                                                        |
| `modelo`                    | `string`   | Model of the product.                                                        |
| `fabricante`                | `string`   | Manufacturer of the product.                                                 |
| `ano_fabricacion`           | `string`   | Year of manufacture of the product.                                          |
| `codigo_barras`             | `string`   | Barcode for the product.                                                     |
| `es_equipo`                 | `string`   | Indicates if the product is equipment (`T`, `F`).                            |
| `es_consumible`             | `string`   | Indicates if the product is consumable (`T`, `F`).                           |
| `permite_operaciones`       | `string`   | Indicates if the product allows operations (`T`, `F`).                       |
| `unidades`                  | `string`   | Unit of measurement for the product.                                         |
| `stock_minimo`              | `numeric`  | Minimum stock level for the product.                                         |
| `stock_maximo`              | `numeric`  | Maximum stock level for the product.                                         |
| `existencias_unidades`      | `numeric`  | Number of units currently in stock.                                          |
| `existencias_cantidad`      | `numeric`  | Total quantity of the product currently in stock.                            |
| `observaciones`             | `string`   | Observations or notes about the product.                                     |
| `es_baja`                   | `string`   | Indicates if the product is inactive (`T`, `F`).                             |
| `referencia`                | `string`   | Reference code for the product.                                              |
| `precio`                    | `numeric`  | Price of the product.                                                        |
| `familia_delegacion`        | `string`   | Delegation code for the product family.                                      |
| `familia_codigo`            | `integer`  | Identifier for the product family.                                           |
| `proveedor_delegacion`      | `string`   | Delegation code for the supplier of the product.                             |
| `proveedor_codigo`          | `string`   | Identifier for the supplier of the product.                                  |

---

##### Validation Rules for Products
The following validation rules apply when creating or updating products:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:15`
- `descripcion`: `nullable|string|max:255`
- `marca`: `nullable|string|max:100`
- `modelo`: `nullable|string|max:100`
- `fabricante`: `nullable|string|max:100`
- `ano_fabricacion`: `nullable|string|max:10`
- `codigo_barras`: `nullable|string|max:100`
- `es_equipo`: `nullable|string|in:T,F|max:1`
- `es_consumible`: `nullable|string|in:T,F|max:1`
- `permite_operaciones`: `nullable|string|in:T,F|max:1`
- `unidades`: `nullable|string|max:20`
- `stock_minimo`: `nullable|numeric|min:0`
- `stock_maximo`: `nullable|numeric|min:0`
- `existencias_unidades`: `nullable|numeric|min:0`
- `existencias_cantidad`: `nullable|numeric|min:0`
- `observaciones`: `nullable|string`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `referencia`: `nullable|string|max:30`
- `precio`: `nullable|numeric|min:0`
- `familia_delegacion`: `nullable|string|max:10`
- `familia_codigo`: `nullable|integer`
- `proveedor_delegacion`: `nullable|string|max:10`
- `proveedor_codigo`: `nullable|string|max:15`

---

#### Families

##### Introduction
The families endpoints allow managing product families in the system. Product families help categorize and organize products effectively.

##### Available Endpoints

###### 1. List Families
- **URL:** `/familias`
- **Method:** `GET`
- **Description:** Retrieves a list of all families in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Electronics",
      "familia_padre_delegacion": "DEL002",
      "familia_padre_codigo": 2
    },
    {
      "delegacion": "DEL002",
      "codigo": 2,
      "descripcion": "Appliances",
      "familia_padre_delegacion": null,
      "familia_padre_codigo": null
    }
  ]
  ```

###### 2. Retrieve Family Details
- **URL:** `/familias/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific family.
- **Parameters:**
  - `codigo` (required): Identifier for the family.
  - `delegacion` (optional): Delegation code associated with the family.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Electronics",
    "familia_padre_delegacion": "DEL002",
    "familia_padre_codigo": 2
  }
  ```

###### 3. Create a Family
- **URL:** `/familias`
- **Method:** `POST`
- **Description:** Creates a new family in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 3,
    "descripcion": "Mobile Devices",
    "familia_padre_delegacion": "DEL001",
    "familia_padre_codigo": 1
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 3,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Family
- **URL:** `/familias/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific family.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Family Description",
    "familia_padre_codigo": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Family
- **URL:** `/familias/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific family from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the family.
  - `delegacion` (optional): Delegation code associated with the family.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Families
The following fields are supported for families:

| **Field**                    | **Type**   | **Description**                                              |
|------------------------------|------------|--------------------------------------------------------------|
| `delegacion`                 | `string`   | Delegation code for the family.                              |
| `codigo`                     | `integer`  | Unique identifier for the family.                            |
| `descripcion`                | `string`   | Description of the family.                                   |
| `familia_padre_delegacion`   | `string`   | Delegation code of the parent family, if applicable.         |
| `familia_padre_codigo`       | `integer`  | Identifier of the parent family, if applicable.              |

---

##### Validation Rules for Families
The following validation rules apply when creating or updating families:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:255`
- `familia_padre_delegacion`: `nullable|string|max:10`
- `familia_padre_codigo`: `nullable|integer`

#### Series or Batches of Products

##### Introduction
The Series or Batches of Products endpoints manage the details related to product batches or series in the system. These endpoints allow the creation, retrieval, updating, and deletion of batch or series information.

##### Available Endpoints

###### 1. List Series or Batches
- **URL:** `/productos-lotes`
- **Method:** `GET`
- **Description:** Retrieves a list of all product series or batches in the system.
- **Example Response:**
  ```json
  [
    {
      "producto_delegacion": "DEL001",
      "producto_codigo": "PRD001",
      "numero_serie_lote": "LOT001",
      "descripcion": "Batch 1",
      "estado": "N",
      "fecha_alta": "2025-01-01"
    },
    {
      "producto_delegacion": "DEL002",
      "producto_codigo": "PRD002",
      "numero_serie_lote": "LOT002",
      "descripcion": "Batch 2",
      "estado": "U",
      "fecha_alta": "2025-01-02"
    }
  ]
  ```

###### 2. Retrieve Series or Batch Details
- **URL:** `/productos-lotes/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific product series or batch.
- **Parameters:**
  - `codigo` (required): Identifier for the series or batch.
  - `delegacion` (optional): Delegation code associated with the series or batch.
  - `clave1` (required): Product code associated with the series or batch.
- **Example Response:**
  ```json
  {
    "producto_delegacion": "DEL001",
    "producto_codigo": "PRD001",
    "numero_serie_lote": "LOT001",
    "descripcion": "Batch 1",
    "estado": "N",
    "fecha_alta": "2025-01-01",
    "fecha_apertura": "2025-01-10",
    "existencias_unidades": 100,
    "existencias_cantidad": 50.5,
    "precio_compra": 20.5,
    "ubicacion_fisica": "Warehouse 1",
    "observaciones": "First batch for PRD001"
  }
  ```

###### 3. Create a Series or Batch
- **URL:** `/productos-lotes`
- **Method:** `POST`
- **Description:** Creates a new series or batch in the system.
- **Request Body:**
  ```json
  {
    "producto_delegacion": "DEL001",
    "producto_codigo": "PRD001",
    "numero_serie_lote": "LOT001",
    "descripcion": "Batch 1",
    "estado": "N",
    "fecha_alta": "2025-01-01",
    "existencias_unidades": 100,
    "existencias_cantidad": 50.5,
    "precio_compra": 20.5,
    "ubicacion_fisica": "Warehouse 1"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "numero_serie_lote": "LOT001",
      "producto_codigo": "PRD001",
      "producto_delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Series or Batch
- **URL:** `/productos-lotes/{codigo}/{delegacion?}/{clave1}`
- **Method:** `PUT`
- **Description:** Updates the details of a specific series or batch.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Batch Description",
    "estado": "U",
    "existencias_unidades": 120
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Series or Batch
- **URL:** `/productos-lotes/{codigo}/{delegacion?}/{clave1}`
- **Method:** `DELETE`
- **Description:** Deletes a specific series or batch from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the series or batch.
  - `delegacion` (optional): Delegation code associated with the series or batch.
  - `clave1` (required): Product code associated with the series or batch.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Series or Batches
The following fields are supported for product series or batches:

| **Field**                   | **Type**   | **Description**                                                                 |
|-----------------------------|------------|---------------------------------------------------------------------------------|
| `producto_delegacion`       | `string`   | Delegation code of the product associated with the batch or series.             |
| `producto_codigo`           | `string`   | Product identifier associated with the batch or series.                         |
| `numero_serie_lote`         | `string`   | Series or batch number.                                                         |
| `descripcion`               | `string`   | Description of the series or batch.                                             |
| `codigo_barras`             | `string`   | Barcode of the series or batch.                                                 |
| `estado`                    | `string`   | State of the series or batch (`N`, `U`, `L`, `F`, `B`).                         |
| `fecha_alta`                | `date`     | Date when the series or batch was created.                                      |
| `fecha_apertura`            | `date`     | Date when the series or batch was opened.                                       |
| `fecha_baja`                | `date`     | Date when the series or batch was deactivated.                                  |
| `cantidad_por_unidad`       | `numeric`  | Amount per unit in the series or batch.                                         |
| `unidades_por_lote`         | `numeric`  | Units per batch.                                                                |
| `existencias_unidades`      | `numeric`  | Units available in stock.                                                       |
| `existencias_cantidad`      | `numeric`  | Quantity available in stock.                                                    |
| `precio_compra`             | `numeric`  | Purchase price of the series or batch.                                          |
| `ubicacion_fisica`          | `string`   | Physical location of the series or batch.                                       |
| `condiciones_ambientales`   | `string`   | Environmental conditions for the series or batch.                               |
| `manual_operacion`          | `string`   | Operation manual associated with the series or batch.                           |
| `especificaciones_tecnicas` | `string`   | Technical specifications of the series or batch.                                |
| `fecha_recepcion`           | `date`     | Reception date of the series or batch.                                          |
| `fecha_calibracion`         | `date`     | Last calibration date of the series or batch.                                   |
| `fecha_mantenimiento`       | `date`     | Last maintenance date of the series or batch.                                   |
| `fecha_verificacion`        | `date`     | Last verification date of the series or batch.                                  |
| `fecha_caducidad`           | `date`     | Expiration date of the series or batch.                                         |
| `fecha_aviso_caducidad`     | `date`     | Expiration warning date for the series or batch.                                |
| `estado_recepcion`          | `string`   | Reception state of the series or batch (`N`, `U`).                              |
| `tipo_fluido`               | `string`   | Type of fluid, if applicable.                                                   |
| `volumen_fluido`            | `string`   | Fluid volume, if applicable.                                                    |
| `reglas_analisis`           | `string`   | Analysis rules associated with the series or batch.                             |
| `generico_1`                | `string`   | Custom or generic field 1 for the series or batch.                              |
| `generico_2`                | `string`   | Custom or generic field 2 for the series or batch.                              |
| `generico_3`                | `string`   | Custom or generic field 3 for the series or batch.                              |
| `generico_4`                | `string`   | Custom or generic field 4 for the series or batch.                              |
| `generico_5`                | `string`   | Custom or generic field 5 for the series or batch.                              |
| `generico_6`                | `string`   | Custom or generic field 6 for the series or batch.                              |
| `observaciones`             | `string`   | Observations or notes about the series or batch.                                |
| `proveedor_delegacion`      | `string`   | Delegation code of the supplier associated with the series or batch.            |
| `proveedor_codigo`          | `string`   | Supplier identifier associated with the series or batch.                        |

---

##### Validation Rules for Series or Batches
The following validation rules apply when creating or updating series or batches:

- `producto_delegacion`: `nullable|string|max:10`
- `producto_codigo`: `required|string|max:15`
- `numero_serie_lote`: `nullable|string|max:30`
- `descripcion`: `nullable|string|max:50`
- `codigo_barras`: `nullable|string|max:100`
- `estado`: `nullable|string|in:N,U,L,F,B|max:1`
- `fecha_alta`: `nullable|date`
- `fecha_apertura`: `nullable|date`
- `fecha_baja`: `nullable|date`
- `cantidad_por_unidad`: `nullable|numeric|min:0`
- `unidades_por_lote`: `nullable|numeric|min:0`
- `existencias_unidades`: `nullable|numeric|min:0`
- `existencias_cantidad`: `nullable|numeric|min:0`
- `precio_compra`: `nullable|numeric|min:0`
- `ubicacion_fisica`: `nullable|string|max:100`
- `condiciones_ambientales`: `nullable|string|max:100`
- `manual_operacion`: `nullable|string|max:255`
- `especificaciones_tecnicas`: `nullable|string|max:255`
- `fecha_recepcion`: `nullable|date`
- `fecha_calibracion`: `nullable|date`
- `fecha_mantenimiento`: `nullable|date`
- `fecha_verificacion`: `nullable|date`
- `fecha_caducidad`: `nullable|date`
- `fecha_aviso_caducidad`: `nullable|date`
- `estado_recepcion`: `nullable|string|in:N,U|max:1`
- `tipo_fluido`: `nullable|string|max:50`
- `volumen_fluido`: `nullable|string|max:50`
- `reglas_analisis`: `nullable|string|max:50`
- `generico_1`: `nullable|string|max:50`
- `generico_2`: `nullable|string|max:50`
- `generico_3`: `nullable|string|max:50`
- `generico_4`: `nullable|string|max:50`
- `generico_5`: `nullable|string|max:50`
- `generico_6`: `nullable|string|max:50`
- `observaciones`: `nullable|string`
- `proveedor_delegacion`: `nullable|string|max:10`
- `proveedor_codigo`: `nullable|string|max:15`

---

#### Raw Materials

##### Introduction
The Raw Materials API provides endpoints to manage raw materials associated with products and batches. These endpoints allow creating, retrieving, updating, and deleting raw material records.

##### Available Endpoints

###### 1. List Raw Materials
- **URL:** `/materias-primas/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves a list of raw materials associated with a specific product or batch.
- **Parameters:**
  - `codigo` (required): Identifier for the product or batch.
  - `delegacion` (optional): Delegation code.
  - `clave1` (required): Series or batch number.
- **Example Response:**
  ```json
  [
    {
      "producto_delegacion": "DEL001",
      "producto_codigo": "PRD001",
      "numero_serie_lote": "LOT001",
      "producto_materia_delegacion": "DEL002",
      "producto_materia_codigo": "MAT001",
      "materia_numero_serie_lote": "MATLOT001",
      "cantidad": 100.5
    },
    {
      "producto_delegacion": "DEL001",
      "producto_codigo": "PRD002",
      "numero_serie_lote": "LOT002",
      "producto_materia_delegacion": "DEL003",
      "producto_materia_codigo": "MAT002",
      "materia_numero_serie_lote": "MATLOT002",
      "cantidad": 50.0
    }
  ]
  ```

###### 2. Retrieve Raw Material Details
- **URL:** `/materias-primas/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}/{clave4}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific raw material.
- **Parameters:**
  - `codigo` (required): Identifier for the product or batch.
  - `delegacion` (optional): Delegation code.
  - `clave1` (required): Series or batch number.
  - `clave2` (required): Raw material identifier.
  - `clave3` (optional): Raw material delegation code.
  - `clave4` (required): Raw material batch or series.
- **Example Response:**
  ```json
  {
    "producto_delegacion": "DEL001",
    "producto_codigo": "PRD001",
    "numero_serie_lote": "LOT001",
    "producto_materia_delegacion": "DEL002",
    "producto_materia_codigo": "MAT001",
    "materia_numero_serie_lote": "MATLOT001",
    "cantidad": 100.5
  }
  ```

###### 3. Create Raw Material
- **URL:** `/materias-primas`
- **Method:** `POST`
- **Description:** Creates a new raw material record.
- **Request Body:**
  ```json
  {
    "producto_delegacion": "DEL001",
    "producto_codigo": "PRD001",
    "numero_serie_lote": "LOT001",
    "producto_materia_delegacion": "DEL002",
    "producto_materia_codigo": "MAT001",
    "materia_numero_serie_lote": "MATLOT001",
    "cantidad": 100.5
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "producto_codigo": "PRD001",
      "numero_serie_lote": "LOT001",
      "producto_materia_codigo": "MAT001",
      "materia_numero_serie_lote": "MATLOT001"
    }
  }
  ```

###### 4. Update Raw Material
- **URL:** `/materias-primas/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}/{clave4}`
- **Method:** `PUT`
- **Description:** Updates the details of an existing raw material.
- **Request Body:**
  ```json
  {
    "cantidad": 120.0
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete Raw Material
- **URL:** `/materias-primas/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}/{clave4}`
- **Method:** `DELETE`
- **Description:** Deletes a specific raw material record.
- **Parameters:**
  - `codigo` (required): Identifier for the product or batch.
  - `delegacion` (optional): Delegation code.
  - `clave1` (required): Series or batch number.
  - `clave2` (required): Raw material identifier.
  - `clave3` (optional): Raw material delegation code.
  - `clave4` (required): Raw material batch or series.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Raw Materials
The following fields are supported for raw materials:

| **Field**                      | **Type**   | **Description**                                                     |
|--------------------------------|------------|---------------------------------------------------------------------|
| `producto_delegacion`          | `string`   | Delegation code of the product using the raw material.              |
| `producto_codigo`              | `string`   | Product identifier using the raw material.                          |
| `numero_serie_lote`            | `string`   | Batch or series number of the product using the raw material.       |
| `producto_materia_delegacion`  | `string`   | Delegation code of the raw material product.                        |
| `producto_materia_codigo`      | `string`   | Identifier of the raw material product.                             |
| `materia_numero_serie_lote`    | `string`   | Batch or series number of the raw material product.                 |
| `cantidad`                     | `numeric`  | Quantity of the raw material used in the product.                   |

---

##### Validation Rules for Raw Materials
The following validation rules apply when creating or updating raw materials:

- `producto_delegacion`: `nullable|string|max:10`
- `producto_codigo`: `required|string|max:15`
- `numero_serie_lote`: `required|string|max:30`
- `producto_materia_delegacion`: `nullable|string|max:10`
- `producto_materia_codigo`: `required|string|max:15`
- `materia_numero_serie_lote`: `required|string|max:30`
- `cantidad`: `nullable|numeric`

#### Employees

##### Introduction
The Employees endpoints allow managing information about employees within the system. These endpoints provide functionality to create, retrieve, update, and delete employee records.

##### Available Endpoints

###### 1. List Employees
- **URL:** `/empleados`
- **Method:** `GET`
- **Description:** Retrieves a list of all employees in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "nombre": "John Doe",
      "telefono": "123456789",
      "email": "johndoe@example.com",
      "es_baja": "F"
    },
    {
      "delegacion": "DEL002",
      "codigo": 2,
      "nombre": "Jane Smith",
      "telefono": "987654321",
      "email": "janesmith@example.com",
      "es_baja": "T"
    }
  ]
  ```

###### 2. Retrieve Employee Details
- **URL:** `/empleados/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation associated with the employee.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "nombre": "John Doe",
    "tratamiento": "Sr.",
    "direccion": "123 Main St",
    "poblacion": "City",
    "provincia": "State",
    "codigo_postal": "12345",
    "telefono": "123456789",
    "movil": "987654321",
    "nif": "12345678A",
    "email": "johndoe@example.com",
    "fecha_alta": "2023-01-01",
    "es_analista": "T"
  }
  ```

###### 3. Create an Employee
- **URL:** `/empleados`
- **Method:** `POST`
- **Description:** Creates a new employee in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "nombre": "John Doe",
    "tratamiento": "Sr.",
    "direccion": "123 Main St",
    "poblacion": "City",
    "provincia": "State",
    "codigo_postal": "12345",
    "telefono": "123456789",
    "email": "johndoe@example.com",
    "es_analista": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update an Employee
- **URL:** `/empleados/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing employee record.
- **Request Body:**
  ```json
  {
    "nombre": "John Updated",
    "telefono": "111111111",
    "email": "johnupdated@example.com"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Employee
- **URL:** `/empleados/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific employee from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation associated with the employee.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Employees
The following fields are supported for employees:

| **Field**          | **Type**   | **Description**                                                                |
|--------------------|------------|--------------------------------------------------------------------------------|
| `delegacion`       | `string`   | Delegation code associated with the employee.                                  |
| `codigo`           | `integer`  | Identifier for the employee.                                                   |
| `nombre`           | `string`   | Full name of the employee.                                                     |
| `tratamiento`      | `string`   | Employee's title or salutation (e.g., Mr., Ms., Dr.).                          |
| `direccion`        | `string`   | Address of the employee.                                                       |
| `poblacion`        | `string`   | City or locality of the employee's address.                                    |
| `provincia`        | `string`   | Province or region of the employee's address.                                  |
| `codigo_postal`    | `string`   | Postal code of the employee's address.                                         |
| `telefono`         | `string`   | Employee's primary phone number.                                               |
| `movil`            | `string`   | Employee's mobile phone number.                                                |
| `nif`              | `string`   | Employee's tax identification number.                                          |
| `nss`              | `string`   | Social Security number of the employee.                                        |
| `cedula`           | `string`   | Identification document number of the employee (if applicable).                |
| `abreviatura`      | `string`   | Abbreviation or alias for the employee.                                        |
| `tipo`             | `string`   | Type or category of the employee (e.g., staff, contractor).                    |
| `email`            | `string`   | Email address of the employee.                                                 |
| `fecha_alta`       | `date`     | Date when the employee joined the organization.                                |
| `fecha_baja`       | `date`     | Date when the employee left the organization (if applicable).                  |
| `observaciones`    | `string`   | Additional observations or notes about the employee.                           |
| `es_analista`      | `string`   | Indicates if the employee is an analyst (`T` for true, `F` for false).         |
| `es_recolector`    | `string`   | Indicates if the employee is a collector (`T` for true, `F` for false).        |
| `es_comercial`     | `string`   | Indicates if the employee is a salesperson (`T` for true, `F` for false).      |
| `es_baja`          | `string`   | Indicates if the employee is inactive (`T` for true, `F` for false).           |

##### Validation Rules for Employees
The following validation rules apply when creating or updating employees:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `nombre`: `nullable|string|max:100`
- `tratamiento`: `nullable|string|max:10`
- `direccion`: `nullable|string|max:255`
- `poblacion`: `nullable|string|max:100`
- `provincia`: `nullable|string|max:100`
- `codigo_postal`: `nullable|string|max:10`
- `telefono`: `nullable|string|max:40`
- `movil`: `nullable|string|max:20`
- `nif`: `nullable|string|max:15`
- `nss`: `nullable|string|max:20`
- `cedula`: `nullable|string|max:20`
- `abreviatura`: `nullable|string|max:20`
- `tipo`: `nullable|string|max:50`
- `email`: `nullable|string|max:100|email`
- `fecha_alta`: `nullable|date`
- `fecha_baja`: `nullable|date`
- `observaciones`: `nullable|string`
- `es_analista`: `nullable|string|in:T,F|max:1`
- `es_recolector`: `nullable|string|in:T,F|max:1`
- `es_comercial`: `nullable|string|in:T,F|max:1`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Employee Positions

##### Introduction
The Employee Positions endpoints allow managing the roles (positions) assigned to employees. This includes assigning positions to employees, updating their roles, and removing them when necessary.

##### Available Endpoints

###### 1. List Employee Positions
- **URL:** `/empleados-cargos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of positions assigned to a specific employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code associated with the employee.
- **Example Response:**
  ```json
  [
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "cargo_delegacion": "DEL001",
      "cargo_codigo": 10,
      "posicion": 1
    },
    {
      "empleado_delegacion": "DEL002",
      "empleado_codigo": 124,
      "cargo_delegacion": "DEL002",
      "cargo_codigo": 11,
      "posicion": 2
    }
  ]
  ```

###### 2. Retrieve Employee Position Details
- **URL:** `/empleados-cargos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific position assigned to an employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code associated with the employee.
  - `clave1` (required): Identifier for the position.
  - `clave2` (optional): Delegation code for the position.
- **Example Response:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "cargo_delegacion": "DEL001",
    "cargo_codigo": 10,
    "posicion": 1
  }
  ```

###### 3. Assign a Position to an Employee
- **URL:** `/empleados-cargos`
- **Method:** `POST`
- **Description:** Assigns a new position to an employee.
- **Request Body:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "cargo_delegacion": "DEL001",
    "cargo_codigo": 10,
    "posicion": 1
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "empleado_codigo": 123,
      "cargo_codigo": 10
    }
  }
  ```

###### 4. Update an Employee Position
- **URL:** `empleados-cargos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates the details of a specific position assigned to an employee.
- **Request Body:**
  ```json
  {
    "posicion": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Remove a Position from an Employee
- **URL:** `/empleados-cargos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Removes a specific position assigned to an employee.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Employee Positions

| **Field**               | **Type**   | **Description**                                  |
|-------------------------|------------|--------------------------------------------------|
| `empleado_delegacion`   | `string`   | Delegation code of the employee.                 |
| `empleado_codigo`       | `integer`  | Identifier of the employee.                      |
| `cargo_delegacion`      | `string`   | Delegation code of the position.                 |
| `cargo_codigo`          | `integer`  | Identifier of the position.                      |
| `posicion`              | `integer`  | Position of the employee within the role.        |

##### Validation Rules for Employee Positions
The following validation rules apply when creating or updating employee positions:

- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `required|integer`
- `cargo_delegacion`: `nullable|string|max:10`
- `cargo_codigo`: `required|integer`
- `posicion`: `nullable|integer|min:1`

#### Employee-Linked Clients

##### Introduction
The Employee-Linked Clients endpoints allow you to manage the relationships between employees and their associated clients in the system.

##### Available Endpoints

###### 1. List Employee-Linked Clients
- **URL:** `/empleados-clientes/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of clients linked to a specific employee.
- **Parameters:**
  - `codigo` (required): Employee code.
  - `delegacion` (optional): Employee delegation.
- **Example Response:**
  ```json
  [
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "cliente_delegacion": "DEL002",
      "cliente_codigo": "CLI001"
    },
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "cliente_delegacion": "DEL003",
      "cliente_codigo": "CLI002"
    }
  ]
  ```

###### 2. Retrieve Linked Client Details
- **URL:** `/empleados-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific client linked to an employee.
- **Parameters:**
  - `codigo` (required): Employee code.
  - `delegacion` (optional): Employee delegation.
  - `clave1` (required): Client code.
  - `clave2` (optional): Client delegation.
- **Example Response:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "cliente_delegacion": "DEL002",
    "cliente_codigo": "CLI001"
  }
  ```

###### 3. Create Employee-Linked Client
- **URL:** `/empleados-clientes`
- **Method:** `POST`
- **Description:** Links a client to an employee.
- **Request Body:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "cliente_delegacion": "DEL002",
    "cliente_codigo": "CLI001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente"
  }
  ```

###### 4. Delete Employee-Linked Client
- **URL:** `/empleados-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Unlinks a client from an employee.
- **Parameters:**
  - `codigo` (required): Employee code.
  - `delegacion` (optional): Employee delegation.
  - `clave1` (required): Client code.
  - `clave2` (optional): Client delegation.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Employee-Linked Clients
The following fields are supported for employee-linked clients:

| **Field**             | **Type**   | **Description**                                               |
|-----------------------|------------|---------------------------------------------------------------|
| `empleado_delegacion` | `string`   | Delegation code associated with the employee.                 |
| `empleado_codigo`     | `integer`  | Identifier for the employee.                                  |
| `cliente_delegacion`  | `string`   | Delegation code associated with the linked client.            |
| `cliente_codigo`      | `string`   | Identifier for the client linked to the employee.             |


##### Validation Rules for Employee-Linked Clients
The following validation rules apply when creating or updating employee-linked clients:

- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `required|integer`
- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `required|string|max:15`

#### Employee Absences

##### Introduction
The Employee Absences API provides endpoints to manage absences associated with employees. This includes creating, retrieving, updating, and deleting absence records.

##### Available Endpoints

###### 1. List Employee Absences
- **URL:** `/empleados-ausencias/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of absences associated with a specific employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code associated with the employee.
- **Example Response:**
  ```json
  [
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "codigo": 1,
      "fecha_inicio": "2025-01-01",
      "fecha_fin": "2025-01-05",
      "descripcion": "Vacation"
    },
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "codigo": 2,
      "fecha_inicio": "2025-02-10",
      "fecha_fin": "2025-02-15",
      "descripcion": "Medical Leave"
    }
  ]
  ```

###### 2. Retrieve Employee Absence Details
- **URL:** `/empleados-ausencias/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific absence associated with an employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code associated with the employee.
  - `clave1` (required): Identifier for the absence.
- **Example Response:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "codigo": 1,
    "fecha_inicio": "2025-01-01",
    "fecha_fin": "2025-01-05",
    "descripcion": "Vacation"
  }
  ```

###### 3. Create an Employee Absence
- **URL:** `/empleados-ausencias`
- **Method:** `POST`
- **Description:** Creates a new absence record for an employee.
- **Request Body:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "codigo": 3,
    "fecha_inicio": "2025-03-01",
    "fecha_fin": "2025-03-05",
    "descripcion": "Training"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 3,
      "empleado_codigo": 123,
      "empleado_delegacion": "DEL001"
    }
  }
  ```

###### 4. Update an Employee Absence
- **URL:** `/empleados-ausencias/{codigo}/{delegacion?}/{clave1}`
- **Method:** `PUT`
- **Description:** Updates the details of a specific absence associated with an employee.
- **Request Body:**
  ```json
  {
    "fecha_inicio": "2025-03-01",
    "fecha_fin": "2025-03-10",
    "descripcion": "Updated Training"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Employee Absence
- **URL:** `/empleados-ausencias/{codigo}/{delegacion?}/{clave1}`
- **Method:** `DELETE`
- **Description:** Deletes a specific absence associated with an employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code associated with the employee.
  - `clave1` (required): Identifier for the absence.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Employee Absences
The following fields are supported for employee absences:

| **Field**              | **Type**   | **Description**                      |
|------------------------|------------|--------------------------------------|
| `empleado_delegacion`  | `string`   | Delegation code for the employee.    |
| `empleado_codigo`      | `integer`  | Identifier for the employee.         |
| `codigo`               | `integer`  | Identifier for the absence.          |
| `fecha_inicio`         | `date`     | Start date of the absence.           |
| `fecha_fin`            | `date`     | End date of the absence.             |
| `descripcion`          | `string`   | Description of the absence.          |

---

##### Validation Rules for Employee Absences
The following validation rules apply when creating or updating employee absences:

- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `nullable|integer`
- `codigo`: `nullable|integer`
- `fecha_inicio`: `nullable|date`
- `fecha_fin`: `nullable|date`
- `descripcion`: `nullable|string|max:50`

#### Employee Curriculum

##### Introduction
The employee curriculum endpoints allow managing the professional history and positions held by employees, including details such as start and end dates, associated roles, and departments.

##### Available Endpoints

###### 1. List Employee Curriculum
- **URL:** `/empleados-curriculum/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves the curriculum records associated with a specific employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code for the employee.
- **Example Response:**
  ```json
  [
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "codigo": 1,
      "fecha_inicio": "2020-01-01",
      "fecha_fin": "2022-12-31",
      "cargo_delegacion": "CAR001",
      "cargo_codigo": 10,
      "departamento_delegacion": "DEP001",
      "departamento_codigo": 5
    }
  ]
  ```

###### 2. Retrieve Employee Curriculum Details
- **URL:** `/empleados-curriculum/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific curriculum entry for an employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code for the employee.
  - `clave1` (required): Identifier for the curriculum entry.
- **Example Response:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "codigo": 1,
    "fecha_inicio": "2020-01-01",
    "fecha_fin": "2022-12-31",
    "cargo_delegacion": "CAR001",
    "cargo_codigo": 10,
    "departamento_delegacion": "DEP001",
    "departamento_codigo": 5
  }
  ```

###### 3. Create Employee Curriculum Entry
- **URL:** `/empleados-curriculum`
- **Method:** `POST`
- **Description:** Adds a new curriculum entry for an employee.
- **Request Body:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "codigo": 1,
    "fecha_inicio": "2020-01-01",
    "fecha_fin": "2022-12-31",
    "cargo_delegacion": "CAR001",
    "cargo_codigo": 10,
    "departamento_delegacion": "DEP001",
    "departamento_codigo": 5
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "empleado_codigo": 123,
      "codigo": 1
    }
  }
  ```

###### 4. Update Employee Curriculum Entry
- **URL:** `/empleados-curriculum/{codigo}/{delegacion?}/{clave1}`
- **Method:** `PUT`
- **Description:** Updates an existing curriculum entry for an employee.
- **Request Body:**
  ```json
  {
    "fecha_inicio": "2021-01-01",
    "fecha_fin": "2023-12-31",
    "cargo_delegacion": "CAR002",
    "cargo_codigo": 15,
    "departamento_delegacion": "DEP002",
    "departamento_codigo": 8
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete Employee Curriculum Entry
- **URL:** `/empleados-curriculum/{codigo}/{delegacion?}/{clave1}`
- **Method:** `DELETE`
- **Description:** Deletes a specific curriculum entry for an employee.
- **Parameters:**
  - `codigo` (required): Identifier for the employee.
  - `delegacion` (optional): Delegation code for the employee.
  - `clave1` (required): Identifier for the curriculum entry.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Employee Curriculum
The following fields are supported for employee curriculum entries:

| **Field**                     | **Type**   | **Description**                                 |
|-------------------------------|------------|-------------------------------------------------|
| `empleado_delegacion`         | `string`   | Delegation code for the employee.               |
| `empleado_codigo`             | `integer`  | Identifier for the employee.                    |
| `codigo`                      | `integer`  | Identifier for the curriculum entry.            |
| `fecha_inicio`                | `date`     | Start date of the position.                     |
| `fecha_fin`                   | `date`     | End date of the position.                       |
| `cargo_delegacion`            | `string`   | Delegation code for the role.                   |
| `cargo_codigo`                | `integer`  | Identifier for the role.                        |
| `departamento_delegacion`     | `string`   | Delegation code for the department.             |
| `departamento_codigo`         | `integer`  | Identifier for the department.                  |

##### Validation Rules for Employee Curriculum
The following validation rules apply when creating or updating employee curriculum entries:

- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `required|integer`
- `codigo`: `nullable|integer`
- `fecha_inicio`: `nullable|date`
- `fecha_fin`: `nullable|date`
- `cargo_delegacion`: `nullable|string|max:10`
- `cargo_codigo`: `nullable|integer`
- `departamento_delegacion`: `nullable|string|max:10`
- `departamento_codigo`: `nullable|integer`

---

#### Employee Training Endpoints

##### Introduction
The Employee Training endpoints allow managing training records related to employees. These endpoints cover functionality to create, retrieve, update, and delete training records, including details like descriptions, observations, start and end dates, and whether evidence is attached or if it's part of the company's training plan.

##### Available Endpoints

###### 1. List Employee Trainings
- **URL:** `/empleados-formacion/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves the list of training records associated with an employee.
- **Parameters:**
  - `codigo` (required): Employee identifier.
  - `delegacion` (optional): Delegation code for the employee.
- **Example Response:**
  ```json
  [
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "codigo": 1,
      "descripcion": "Health and Safety Training",
      "fecha_inicio": "2025-01-01",
      "fecha_fin": "2025-01-02",
      "es_plan_empresa": "T"
    },
    {
      "empleado_delegacion": "DEL001",
      "empleado_codigo": 123,
      "codigo": 2,
      "descripcion": "Technical Training",
      "fecha_inicio": "2025-02-15",
      "fecha_fin": "2025-02-20",
      "es_plan_empresa": "F"
    }
  ]
  ```

###### 2. Retrieve Employee Training Details
- **URL:** `/empleados-formacion/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific training record associated with an employee.
- **Parameters:**
  - `codigo` (required): Employee identifier.
  - `delegacion` (optional): Delegation code for the employee.
  - `clave1` (required): Training identifier.
- **Example Response:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "codigo": 1,
    "descripcion": "Health and Safety Training",
    "observaciones": "Mandatory training for all employees.",
    "fecha_inicio": "2025-01-01",
    "fecha_fin": "2025-01-02",
    "adjunta_evidencia": "T",
    "es_plan_empresa": "T"
  }
  ```

###### 3. Create an Employee Training Record
- **URL:** `/empleados-formacion`
- **Method:** `POST`
- **Description:** Creates a new training record for an employee.
- **Request Body:**
  ```json
  {
    "empleado_delegacion": "DEL001",
    "empleado_codigo": 123,
    "descripcion": "Advanced Technical Training",
    "observaciones": "Optional training for technical staff.",
    "fecha_inicio": "2025-03-01",
    "fecha_fin": "2025-03-05",
    "adjunta_evidencia": "F",
    "es_plan_empresa": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 3,
      "empleado_codigo": 123,
      "empleado_delegacion": "DEL001"
    }
  }
  ```

###### 4. Update an Employee Training Record
- **URL:** `/empleados-formacion/{codigo}/{delegacion?}/{clave1}`
- **Method:** `PUT`
- **Description:** Updates an existing training record for an employee.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Training Description",
    "observaciones": "Updated observations for the training.",
    "fecha_inicio": "2025-03-10",
    "fecha_fin": "2025-03-15",
    "adjunta_evidencia": "T",
    "es_plan_empresa": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Employee Training Record
- **URL:** `/empleados-formacion/{codigo}/{delegacion?}/{clave1}`
- **Method:** `DELETE`
- **Description:** Deletes a specific training record associated with an employee.
- **Parameters:**
  - `codigo` (required): Employee identifier.
  - `delegacion` (optional): Delegation code for the employee.
  - `clave1` (required): Training identifier.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Employee Training

The following fields are supported for training records:

| **Field**             | **Type**   | **Description**                                                 |
|-----------------------|------------|-----------------------------------------------------------------|
| `empleado_delegacion` | `string`   | Delegation code associated with the employee.                   |
| `empleado_codigo`     | `integer`  | Identifier for the employee.                                    |
| `codigo`              | `integer`  | Identifier for the training record.                             |
| `descripcion`         | `string`   | Description of the training.                                    |
| `observaciones`       | `string`   | Observations or notes about the training.                       |
| `fecha_inicio`        | `date`     | Start date of the training.                                     |
| `fecha_fin`           | `date`     | End date of the training.                                       |
| `adjunta_evidencia`   | `string`   | Indicates if evidence is attached (`T` for true, `F` for false).|
| `es_plan_empresa`     | `string`   | Indicates if the training is part of the plan (`T`,`F`).        |

##### Validation Rules for Employee Training
The following validation rules apply when creating or updating training records:

- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `nullable|integer`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:50`
- `observaciones`: `nullable|string|max:255`
- `fecha_inicio`: `nullable|date`
- `fecha_fin`: `nullable|date`
- `adjunta_evidencia`: `nullable|string|in:T,F|max:1`
- `es_plan_empresa`: `nullable|string|in:T,F|max:1`

#### Courses

##### Introduction
The Courses endpoints allows managing information about training courses, including their descriptions, schedules, objectives, and associated details. These endpoints provide functionality to create, retrieve, update, and delete course records.

##### Available Endpoints

###### 1. List Courses
- **URL:** `/cursos`
- **Method:** `GET`
- **Description:** Retrieves a list of all courses in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "C001",
      "estado": "P",
      "fecha_prevista": "2025-01-01",
      "fecha_inicio": "2025-01-10",
      "fecha_fin": "2025-01-20",
      "horas_duracion": 40,
      "tipo": "I",
      "descripcion": "Course 1 Description"
    },
    {
      "delegacion": "DEL002",
      "codigo": "C002",
      "estado": "J",
      "fecha_prevista": "2025-02-01",
      "fecha_inicio": "2025-02-10",
      "fecha_fin": "2025-02-20",
      "horas_duracion": 30,
      "tipo": "E",
      "descripcion": "Course 2 Description"
    }
  ]
  ```

###### 2. Retrieve Course Details
- **URL:** `/cursos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific course.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "C001",
    "estado": "P",
    "fecha_prevista": "2025-01-01",
    "fecha_inicio": "2025-01-10",
    "fecha_fin": "2025-01-20",
    "horas_duracion": 40,
    "dias_plazo_evaluar": 15,
    "tipo": "I",
    "descripcion": "Course 1 Description",
    "organismo": "External Organization",
    "observaciones": "Some observations",
    "objetivos": "Course objectives",
    "programa": "Detailed program"
  }
  ```

###### 3. Create a Course
- **URL:** `/cursos`
- **Method:** `POST`
- **Description:** Creates a new course in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "C003",
    "estado": "P",
    "fecha_prevista": "2025-03-01",
    "fecha_inicio": "2025-03-10",
    "fecha_fin": "2025-03-20",
    "horas_duracion": 25,
    "dias_plazo_evaluar": 10,
    "tipo": "I",
    "descripcion": "Course 3 Description",
    "organismo": "Another Organization",
    "observaciones": "Some notes",
    "objetivos": "Key objectives",
    "programa": "Program details"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "C003",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Course
- **URL:** `/cursos/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates the information of a specific course.
- **Request Body:**
  ```json
  {
    "estado": "E",
    "horas_duracion": 35,
    "observaciones": "Updated notes",
    "objetivos": "Updated objectives"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Course
- **URL:** `/cursos/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific course from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Courses
The following fields are supported for courses:

| **Field**               | **Type**   | **Description**                                           |
|-------------------------|------------|-----------------------------------------------------------|
| `delegacion`            | `string`   | Delegation code associated with the course.               |
| `codigo`                | `string`   | Identifier for the course.                                |
| `estado`                | `string`   | Current state (`P`, `J`, `R`, `E`, `S`, `C`, `H`).        |
| `fecha_prevista`        | `date`     | Scheduled date for the course.                            |
| `fecha_inicio`          | `date`     | Start date of the course.                                 |
| `fecha_fin`             | `date`     | End date of the course.                                   |
| `horas_duracion`        | `integer`  | Duration of the course in hours.                          |
| `dias_plazo_evaluar`    | `integer`  | Number of days allowed for evaluation after the course.   |
| `tipo`                  | `string`   | Type of the course (`I` for internal, `E` for external).  |
| `descripcion`           | `string`   | Description of the course.                                |
| `organismo`             | `string`   | Organization conducting the course.                       |
| `observaciones`         | `string`   | Observations or notes about the course.                   |
| `objetivos`             | `string`   | Objectives of the course.                                 |
| `programa`              | `string`   | Program or curriculum of the course.                      |

##### Validation Rules for Courses
The following validation rules apply when creating or updating courses:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:15`
- `estado`: `nullable|string|in:P,J,R,E,S,C,H|max:1`
- `fecha_prevista`: `nullable|date`
- `fecha_inicio`: `nullable|date`
- `fecha_fin`: `nullable|date`
- `horas_duracion`: `nullable|integer`
- `dias_plazo_evaluar`: `nullable|integer`
- `tipo`: `nullable|string|in:I,E|max:1`
- `descripcion`: `nullable|string|max:100`
- `organismo`: `nullable|string|max:100`
- `observaciones`: `nullable|string|max:255`
- `objetivos`: `nullable|string|max:255`
- `programa`: `nullable|string`

---

#### Courses Students

##### Introduction
The Courses Students API provides endpoints to manage the relationship between courses and employees (students). These endpoints allow adding, retrieving, updating, and deleting course enrollment records for employees.

##### Available Endpoints

###### 1. List Students in a Course
- **URL:** `/cursos-alumnos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of students enrolled in a specific course.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
- **Example Response:**
  ```json
  [
    {
      "curso_delegacion": "DEL001",
      "curso_codigo": "C001",
      "empleado_delegacion": "EMP001",
      "empleado_codigo": "E001",
      "evaluacion": 85,
      "adjunta_evidencia": "T",
      "no_finalizo": "F",
      "comentarios": "Good performance",
      "empleado_evaluador_delegacion": "EVAL001",
      "empleado_evaluador_codigo": "EVAL001"
    }
  ]
  ```

###### 2. Retrieve Student Details
- **URL:** `/cursos-alumnos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific student enrolled in a course.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
  - `clave1` (required): Identifier for the student (employee).
  - `clave2` (optional): Delegation code for the employee.
- **Example Response:**
  ```json
  {
    "curso_delegacion": "DEL001",
    "curso_codigo": "C001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001",
    "evaluacion": 85,
    "adjunta_evidencia": "T",
    "no_finalizo": "F",
    "comentarios": "Good performance",
    "empleado_evaluador_delegacion": "EVAL001",
    "empleado_evaluador_codigo": "EVAL001"
  }
  ```

###### 3. Enroll a Student in a Course
- **URL:** `/cursos-alumnos`
- **Method:** `POST`
- **Description:** Enrolls a new student in a course.
- **Request Body:**
  ```json
  {
    "curso_delegacion": "DEL001",
    "curso_codigo": "C001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001",
    "evaluacion": 85,
    "adjunta_evidencia": "T",
    "no_finalizo": "F",
    "comentarios": "Good performance",
    "empleado_evaluador_delegacion": "EVAL001",
    "empleado_evaluador_codigo": "EVAL001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "curso_codigo": "C001",
      "curso_delegacion": "DEL001",
      "empleado_codigo": "E001",
      "empleado_delegacion": "EMP001"
    }
  }
  ```

###### 4. Update Student Enrollment
- **URL:** `/cursos-alumnos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing student enrollment record in a course.
- **Request Body:**
  ```json
  {
    "evaluacion": 90,
    "adjunta_evidencia": "T",
    "no_finalizo": "F",
    "comentarios": "Updated performance",
    "empleado_evaluador_delegacion": "EVAL001",
    "empleado_evaluador_codigo": "EVAL002"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Remove a Student from a Course
- **URL:** `/cursos-alumnos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific student enrollment record from a course.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
  - `clave1` (required): Identifier for the student (employee).
  - `clave2` (optional): Delegation code for the employee.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Courses Students
The following fields are supported for the Courses Students API:

| **Field**                       | **Type**   | **Description**                                                  |
|---------------------------------|------------|------------------------------------------------------------------|
| `curso_delegacion`              | `string`   | Delegation code of the course.                                   |
| `curso_codigo`                  | `string`   | Identifier for the course.                                       |
| `empleado_delegacion`           | `string`   | Delegation code of the enrolled employee.                        |
| `empleado_codigo`               | `integer`  | Identifier for the enrolled employee.                            |
| `evaluacion`                    | `integer`  | Evaluation score given to the employee for the course.           |
| `adjunta_evidencia`             | `string`   | Indicates if evidence is attached (`T` for true, `F` for false). |
| `no_finalizo`                   | `string`   | Indicates if the employee did not complete the course (`T`, `F`).|
| `comentarios`                   | `string`   | Comments or notes about the student's performance.               |
| `empleado_evaluador_delegacion` | `string`   | Delegation code of the employee who evaluated the student.       |
| `empleado_evaluador_codigo`     | `integer`  | Identifier of the employee who evaluated the student.            |

##### Validation Rules for Courses Students
The following validation rules apply when creating or updating course enrollments:

- `curso_delegacion`: `nullable|string|max:10`
- `curso_codigo`: `required|string|max:15`
- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `required|integer`
- `evaluacion`: `nullable|integer`
- `adjunta_evidencia`: `nullable|string|in:T,F|max:1`
- `no_finalizo`: `nullable|string|in:T,F|max:1`
- `comentarios`: `nullable|string|max:255`
- `empleado_evaluador_delegacion`: `nullable|string|max:10`
- `empleado_evaluador_codigo`: `nullable|integer`

#### Courses Teachers

##### Introduction
The Courses Teachers API provides endpoints to manage the relationship between courses and employees (teachers). These endpoints allow adding, retrieving, and deleting teacher assignments to courses.

##### Available Endpoints

###### 1. List Teachers in a Course
- **URL:** `/cursos-profesores/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of teachers assigned to a specific course.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
- **Example Response:**
  ```json
  [
    {
      "curso_delegacion": "DEL001",
      "curso_codigo": "C001",
      "empleado_delegacion": "EMP001",
      "empleado_codigo": "E001"
    }
  ]
  ```

###### 2. Retrieve Teacher Details
- **URL:** `/cursos-profesores/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific teacher assigned to a course.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
  - `clave1` (required): Identifier for the teacher (employee).
  - `clave2` (optional): Delegation code for the employee.
- **Example Response:**
  ```json
  {
    "curso_delegacion": "DEL001",
    "curso_codigo": "C001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001"
  }
  ```

###### 3. Assign a Teacher to a Course
- **URL:** `/cursos-profesores`
- **Method:** `POST`
- **Description:** Assigns a new teacher to a course.
- **Request Body:**
  ```json
  {
    "curso_delegacion": "DEL001",
    "curso_codigo": "C001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "curso_codigo": "C001",
      "curso_delegacion": "DEL001",
      "empleado_codigo": "E001",
      "empleado_delegacion": "EMP001"
    }
  }
  ```

###### 4. Remove a Teacher from a Course
- **URL:** `/cursos-profesores/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific teacher assignment from a course.
- **Parameters:**
  - `codigo` (required): Identifier for the course.
  - `delegacion` (optional): Delegation code associated with the course.
  - `clave1` (required): Identifier for the teacher (employee).
  - `clave2` (optional): Delegation code for the employee.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Courses Teachers
The following fields are supported for the Courses Teachers API:

| **Field**               | **Type**   | **Description**                                 |
|-------------------------|------------|-------------------------------------------------|
| `curso_delegacion`      | `string`   | Delegation code of the course.                  |
| `curso_codigo`          | `string`   | Identifier for the course.                      |
| `empleado_delegacion`   | `string`   | Delegation code of the teacher.                 |
| `empleado_codigo`       | `integer`  | Id. for the teacher associated with the course. |

##### Validation Rules for Courses Teachers
The following validation rules apply when creating or updating teacher assignments:

- `curso_delegacion`: `nullable|string|max:10`
- `curso_codigo`: `required|string|max:15`
- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `required|integer`

#### Positions

##### Introduction
The Positions API provides endpoints to manage job positions in the system. These endpoints allow creating, retrieving, updating, and deleting positions.

##### Available Endpoints

###### 1. List Positions
- **URL:** `/cargos`
- **Method:** `GET`
- **Description:** Retrieves a list of all positions in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "nombre": "Manager",
      "certificaciones": "Leadership",
      "requerimientos": "5 years experience",
      "experiencia": "5+ years",
      "caracteristicas": "Leadership skills",
      "observaciones": "Handles department",
      "es_baja": "F",
      "departamento_delegacion": "DEP001",
      "departamento_codigo": 10,
      "cargo_superior_delegacion": "CAR001",
      "cargo_superior_codigo": 5
    }
  ]
  ```

###### 2. Retrieve Position Details
- **URL:** `/cargos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific position.
- **Parameters:**
  - `codigo` (required): Identifier of the position.
  - `delegacion` (optional): Delegation code associated with the position.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "nombre": "Manager",
    "certificaciones": "Leadership",
    "requerimientos": "5 years experience",
    "experiencia": "5+ years",
    "caracteristicas": "Leadership skills",
    "observaciones": "Handles department",
    "es_baja": "F",
    "departamento_delegacion": "DEP001",
    "departamento_codigo": 10,
    "cargo_superior_delegacion": "CAR001",
    "cargo_superior_codigo": 5
  }
  ```

###### 3. Create a Position
- **URL:** `/cargos`
- **Method:** `POST`
- **Description:** Creates a new position.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "nombre": "Manager",
    "certificaciones": "Leadership",
    "requerimientos": "5 years experience",
    "experiencia": "5+ years",
    "caracteristicas": "Leadership skills",
    "observaciones": "Handles department",
    "es_baja": "F",
    "departamento_delegacion": "DEP001",
    "departamento_codigo": 10,
    "cargo_superior_delegacion": "CAR001",
    "cargo_superior_codigo": 5
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Position
- **URL:** `/cargos/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing position.
- **Request Body:**
  ```json
  {
    "nombre": "Senior Manager",
    "certificaciones": "Advanced Leadership",
    "requerimientos": "10 years experience",
    "experiencia": "10+ years",
    "caracteristicas": "Advanced leadership skills",
    "observaciones": "Oversees multiple departments"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Position
- **URL:** `/cargos/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a position.
- **Parameters:**
  - `codigo` (required): Identifier of the position.
  - `delegacion` (optional): Delegation code associated with the position.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Positions
The following fields are supported for the Positions API:

| **Field**                     | **Type**   | **Description**                                  |
|-------------------------------|------------|--------------------------------------------------|
| `delegacion`                  | `string`   | Delegation code associated with the position.    |
| `codigo`                      | `integer`  | Identifier for the position.                     |
| `nombre`                      | `string`   | Name of the position.                            |
| `certificaciones`             | `string`   | Certifications required for the position.        |
| `requerimientos`              | `string`   | Requirements for the position.                   |
| `experiencia`                 | `string`   | Experience needed for the position.              |
| `caracteristicas`             | `string`   | Characteristics or qualities of the position.    |
| `observaciones`               | `string`   | Observations about the position.                 |
| `es_baja`                     | `string`   | Indicates if the position is inactive (`T`, `F`).|
| `departamento_delegacion`     | `string`   | Delegation code of the department.               |
| `departamento_codigo`         | `integer`  | Identifier of the department.                    |
| `cargo_superior_delegacion`   | `string`   | Delegation code of the superior position.        |
| `cargo_superior_codigo`       | `integer`  | Identifier of the superior position.             |

##### Validation Rules for Positions
The following validation rules apply when creating or updating positions:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `nombre`: `nullable|string|max:100`
- `certificaciones`: `nullable|string`
- `requerimientos`: `nullable|string`
- `experiencia`: `nullable|string`
- `caracteristicas`: `nullable|string`
- `observaciones`: `nullable|string`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `departamento_delegacion`: `nullable|string|max:10`
- `departamento_codigo`: `nullable|integer`
- `cargo_superior_delegacion`: `nullable|string|max:10`
- `cargo_superior_codigo`: `nullable|integer`

#### Positions Tasks

##### Introduction
The Positions Tasks API provides endpoints to manage tasks associated with specific positions. These endpoints allow adding, retrieving, updating, and deleting tasks for positions.

##### Available Endpoints

###### 1. List Tasks of a Position
- **URL:** `/cargos-tareas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of tasks associated with a specific position.
- **Parameters:**
  - `codigo` (required): Identifier for the position.
  - `delegacion` (optional): Delegation code associated with the position.
- **Example Response:**
  ```json
  [
    {
      "cargo_delegacion": "DEL001",
      "cargo_codigo": "C001",
      "codigo": "T001",
      "descripcion": "Task 1 description"
    },
    {
      "cargo_delegacion": "DEL001",
      "cargo_codigo": "C001",
      "codigo": "T002",
      "descripcion": "Task 2 description"
    }
  ]
  ```

###### 2. Retrieve Task Details
- **URL:** `/cargos-tareas/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific task of a position.
- **Parameters:**
  - `codigo` (required): Identifier for the position.
  - `delegacion` (optional): Delegation code associated with the position.
  - `clave1` (required): Identifier for the task.
- **Example Response:**
  ```json
  {
    "cargo_delegacion": "DEL001",
    "cargo_codigo": "C001",
    "codigo": "T001",
    "descripcion": "Task 1 detailed description"
  }
  ```

###### 3. Add a Task to a Position
- **URL:** `/cargos-tareas`
- **Method:** `POST`
- **Description:** Adds a new task to a position.
- **Request Body:**
  ```json
  {
    "cargo_delegacion": "DEL001",
    "cargo_codigo": "C001",
    "codigo": "T003",
    "descripcion": "New task description"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "cargo_delegacion": "DEL001",
      "cargo_codigo": "C001",
      "codigo": "T003"
    }
  }
  ```

###### 4. Update a Task of a Position
- **URL:** `/cargos-tareas/{codigo}/{delegacion?}/{clave1}`
- **Method:** `PUT`
- **Description:** Updates an existing task associated with a position.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated task description"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Task from a Position
- **URL:** `/cargos-tareas/{codigo}/{delegacion?}/{clave1}`
- **Method:** `DELETE`
- **Description:** Deletes a specific task associated with a position.
- **Parameters:**
  - `codigo` (required): Identifier for the position.
  - `delegacion` (optional): Delegation code associated with the position.
  - `clave1` (required): Identifier for the task.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Positions Tasks
The following fields are supported for the Positions Tasks API:

| **Field**            | **Type**   | **Description**                                      |
|----------------------|------------|------------------------------------------------------|
| `cargo_delegacion`   | `string`   | Delegation code associated with the position.        |
| `cargo_codigo`       | `integer`  | Identifier of the position.                          |
| `codigo`             | `integer`  | Identifier of the task associated with the position. |
| `descripcion`        | `string`   | Description of the task.                             |

##### Validation Rules for Positions Tasks
The following validation rules apply when creating or updating tasks for positions:

- `cargo_delegacion`: `nullable|string|max:10`
- `cargo_codigo`: `required|integer`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:255`

#### Departments

##### Introduction
The Departments API provides endpoints to manage department records. These endpoints allow creating, retrieving, updating, and deleting department data.

##### Available Endpoints

###### 1. List Departments
- **URL:** `/departamentos`
- **Method:** `GET`
- **Description:** Retrieves a list of all departments in the system.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "nombre": "Human Resources",
      "es_baja": "F"
    },
    {
      "delegacion": "DEL002",
      "codigo": 2,
      "nombre": "IT Department",
      "es_baja": "F"
    }
  ]
  ```

###### 2. Retrieve Department Details
- **URL:** `/departamentos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific department.
- **Parameters:**
  - `codigo` (required): Identifier for the department.
  - `delegacion` (optional): Delegation code associated with the department.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "nombre": "Human Resources",
    "es_baja": "F"
  }
  ```

###### 3. Create a Department
- **URL:** `/departamentos`
- **Method:** `POST`
- **Description:** Creates a new department record in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 3,
    "nombre": "Finance Department",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 3,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Department
- **URL:** `/departamentos/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing department record.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Department Name",
    "es_baja": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Department
- **URL:** `/departamentos/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific department record.
- **Parameters:**
  - `codigo` (required): Identifier for the department.
  - `delegacion` (optional): Delegation code associated with the department.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Departments
The following fields are supported for departments in the system:

| **Field**     | **Type**   | **Description**                                     |
|---------------|------------|-----------------------------------------------------|
| `delegacion`  | `string`   | Delegation code associated with the department.     |
| `codigo`      | `integer`  | Unique identifier of the department.                |
| `nombre`      | `string`   | Name of the department.                             |
| `es_baja`     | `string`   | Indicates if the department is inactive (`T`, `F`). |

##### Validation Rules for Departments
The following validation rules apply when creating or updating departments:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `nombre`: `nullable|string|max:50`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Users

##### Introduction
The Users API provides endpoints to manage user accounts within the system. These endpoints allow the creation, retrieval, updating, and deletion of user records.

##### Available Endpoints

###### 1. List Users
- **URL:** `/usuarios`
- **Method:** `GET`
- **Description:** Retrieves a list of all users in the system.
- **Parameters:** None.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "USR001",
      "nombre": "John Doe",
      "es_conectado": "T",
      "idioma": 1,
      "observaciones": "Admin user",
      "fecha_alta": "2025-01-01",
      "perfil_codigo": 101
    },
    {
      "delegacion": "DEL002",
      "codigo": "USR002",
      "nombre": "Jane Smith",
      "es_conectado": "F",
      "idioma": 2,
      "observaciones": "Guest user",
      "fecha_alta": "2025-01-10",
      "perfil_codigo": 102
    }
  ]
  ```

###### 2. Retrieve User Details
- **URL:** `/usuarios/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific user.
- **Parameters:**
  - `codigo` (required): Identifier for the user.
  - `delegacion` (optional): Delegation code associated with the user.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "USR001",
    "nombre": "John Doe",
    "es_conectado": "T",
    "idioma": 1,
    "certificado": "Cert123",
    "usuario_windows": "jdoe",
    "observaciones": "Admin user",
    "tipo": 1,
    "fecha_alta": "2025-01-01",
    "fecha_baja": null,
    "perfil_codigo": 101
  }
  ```

###### 3. Create a User
- **URL:** `/usuarios`
- **Method:** `POST`
- **Description:** Creates a new user in the system.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "USR003",
    "nombre": "Michael Brown",
    "es_conectado": "T",
    "idioma": 1,
    "certificado": "Cert456",
    "usuario_windows": "mbrown",
    "observaciones": "New employee",
    "tipo": 2,
    "fecha_alta": "2025-02-01",
    "perfil_codigo": 103
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "USR003",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a User
- **URL:** `/usuarios/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates information for a specific user.
- **Request Body:**
  ```json
  {
    "nombre": "Michael B. Brown",
    "es_conectado": "F",
    "idioma": 2,
    "observaciones": "Updated user information",
    "tipo": 3,
    "perfil_codigo": 104
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a User
- **URL:** `/usuarios/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific user from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the user.
  - `delegacion` (optional): Delegation code associated with the user.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Users
The following fields are supported for the Users API:

| **Field**                     | **Type**   | **Description**                                         |
|-------------------------------|------------|---------------------------------------------------------|
| `delegacion`                  | `string`   | Delegation code associated with the user.               |
| `codigo`                      | `string`   | Unique identifier of the user.                          |
| `nombre`                      | `string`   | Name of the user.                                       |
| `es_conectado`                | `string`   | Indicates if the user is currently connected (`T`, `F`).|
| `idioma`                      | `integer`  | Language code used by the user.                         |
| `certificado`                 | `string`   | Certification associated with the user.                 |
| `usuario_windows`             | `string`   | Windows username for the user.                          |
| `sid_windows`                 | `string`   | Windows Security Identifier (SID) of the user.          |
| `ocultar_aviso_minimizar`     | `string`   | Indicates if the minimize warning is hidden (`T`, `F`). |
| `observaciones`               | `string`   | Observations or notes about the user.                   |
| `tipo`                        | `integer`  | Type of the user, represented as an integer.            |
| `fecha_alta`                  | `date`     | Registration date of the user.                          |
| `fecha_baja`                  | `date`     | Deactivation date of the user.                          |
| `fecha_ultimo_acceso`         | `date`     | Last access date of the user.                           |
| `perfil_delegacion`           | `string`   | Delegation code associated with the user's profile.     |
| `perfil_codigo`               | `integer`  | Profile code associated with the user.                  |
| `empleado_delegacion`         | `string`   | Delegation code associated with the linked employee.    |
| `empleado_codigo`             | `integer`  | Code of the linked employee.                            |
| `cliente_delegacion`          | `string`   | Delegation code associated with the linked client.      |
| `cliente_codigo`              | `string`   | Code of the linked client.                              |

##### Validation Rules for Users
The following validation rules apply when creating or updating users:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:15`
- `nombre`: `nullable|string|max:100`
- `es_conectado`: `nullable|string|in:T,F|max:1`
- `idioma`: `nullable|integer|min:0`
- `certificado`: `nullable|string|max:100`
- `usuario_windows`: `nullable|string|max:50`
- `sid_windows`: `nullable|string|max:50`
- `ocultar_aviso_minimizar`: `nullable|string|in:T,F|max:1`
- `observaciones`: `nullable|string`
- `tipo`: `nullable|integer`
- `fecha_alta`: `nullable|date`
- `fecha_baja`: `nullable|date`
- `fecha_ultimo_acceso`: `nullable|date`
- `perfil_delegacion`: `nullable|string|max:10`
- `perfil_codigo`: `nullable|integer`
- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `nullable|integer`
- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `nullable|string|max:15`

#### Delegations

##### Introduction
The Delegations API provides endpoints to manage delegations in the system. Delegations represent different branches or locations associated with the business. These endpoints allow creating, retrieving, updating, and deleting delegation records.

##### Available Endpoints

###### 1. List Delegations
- **URL:** `/delegaciones`
- **Method:** `GET`
- **Description:** Retrieves a list of all delegations in the system.
- **Example Response:**
  ```json
  [
    {
      "codigo": "DEL001",
      "nombre": "Head Office",
      "direccion": "123 Main Street",
      "codigo_postal": "12345",
      "provincia": "Province",
      "poblacion": "City",
      "pais": "ES",
      "telefono": "123456789",
      "email": "office@example.com",
      "nif": "A12345678",
      "razon": "Main Headquarters"
    }
  ]
  ```

###### 2. Retrieve Delegation Details
- **URL:** `/delegaciones/{codigo}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific delegation.
- **Parameters:**
  - `codigo` (required): Identifier for the delegation.
- **Example Response:**
  ```json
  {
    "codigo": "DEL001",
    "nombre": "Head Office",
    "direccion": "123 Main Street",
    "codigo_postal": "12345",
    "provincia": "Province",
    "poblacion": "City",
    "pais": "ES",
    "telefono": "123456789",
    "email": "office@example.com",
    "nif": "A12345678",
    "razon": "Main Headquarters"
  }
  ```

###### 3. Create a Delegation
- **URL:** `/delegaciones`
- **Method:** `POST`
- **Description:** Creates a new delegation in the system.
- **Request Body:**
  ```json
  {
    "codigo": "DEL002",
    "nombre": "Branch Office",
    "direccion": "456 Another St",
    "codigo_postal": "54321",
    "provincia": "Province2",
    "poblacion": "Town",
    "pais": "ES",
    "telefono": "987654321",
    "email": "branch@example.com",
    "nif": "B98765432",
    "razon": "Regional Office"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "DEL002"
    }
  }
  ```

###### 4. Update a Delegation
- **URL:** `/delegaciones/{codigo}`
- **Method:** `PUT`
- **Description:** Updates information about a specific delegation.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Office Name",
    "direccion": "Updated Address"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Delegation
- **URL:** `/delegaciones/{codigo}`
- **Method:** `DELETE`
- **Description:** Deletes a specific delegation from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the delegation.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Delegations
The following fields are supported for delegations in the system:

| **Field**        | **Type**   | **Description**                                      |
|------------------|------------|------------------------------------------------------|
| `codigo`         | `string`   | Unique identifier for the delegation.                |
| `nombre`         | `string`   | Name of the delegation.                              |
| `direccion`      | `string`   | Address of the delegation.                           |
| `codigo_postal`  | `string`   | Postal code of the delegation.                       |
| `provincia`      | `string`   | Province where the delegation is located.            |
| `poblacion`      | `string`   | City or town where the delegation is located.        |
| `pais`           | `string`   | Country code of the delegation.                      |
| `telefono`       | `string`   | Phone number of the delegation.                      |
| `movil`          | `string`   | Mobile phone number of the delegation.               |
| `fax`            | `string`   | Fax number of the delegation.                        |
| `email`          | `string`   | Email address of the delegation.                     |
| `nif`            | `string`   | Tax identification number of the delegation.         |
| `razon`          | `string`   | Legal name or corporate name of the delegation.      |
| `tipo_persona`   | `string`   | Type of entity (`F`: Individual, `J`: Corporation).  |
| `residencia`     | `string`   | Residence type (`E`: Spain, `R`: EU, `U`: Other).    |
| `moneda`         | `string`   | Currency code used in the delegation.                |
| `lengua`         | `string`   | Language code used in the delegation.                |
| `observaciones`  | `string`   | Observations about the delegation.                   |
| `fecha_alta`     | `date`     | Date when the delegation was created or registered.  |
| `fecha_baja`     | `date`     | Date when the delegation was deactivated.            |
| `es_baja`        | `string`   | Indicates if is deactivated (`T`, `F`).              |

##### Validation Rules for Delegations
The following validation rules apply when creating or updating delegations:

- `codigo`: `nullable|string|max:10`
- `nombre`: `nullable|string|max:100`
- `direccion`: `nullable|string|max:255`
- `codigo_postal`: `nullable|string|max:10`
- `provincia`: `nullable|string|max:100`
- `poblacion`: `nullable|string|max:100`
- `pais`: `nullable|string|max:3`
- `telefono`: `nullable|string|max:20`
- `movil`: `nullable|string|max:20`
- `fax`: `nullable|string|max:20`
- `email`: `nullable|string|max:100`
- `nif`: `nullable|string|max:15`
- `razon`: `nullable|string|max:255`
- `tipo_persona`: `nullable|string|in:F,J|max:1`
- `residencia`: `nullable|string|in:E,R,U|max:1`
- `moneda`: `nullable|string|max:3`
- `lengua`: `nullable|string|max:2`
- `observaciones`: `nullable|string`
- `fecha_alta`: `nullable|date`
- `fecha_baja`: `nullable|date`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### User Profiles

##### Introduction
The User Profiles API provides endpoints to manage user profiles within the system. These endpoints allow for creating, retrieving, updating, and deleting profiles.

##### Available Endpoints

###### 1. List Profiles
- **URL:** `/perfiles`
- **Method:** `GET`
- **Description:** Retrieves a list of all user profiles.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Admin Profile",
      "estado_desde": 0,
      "estado_hasta": 7,
      "precios_restringidos": "T",
      "campos_operaciones": "ALL",
      "campos_resultados": "ALL",
      "tipo_firma_delegacion": "DEL001",
      "tipo_firma_codigo": 2
    }
  ]
  ```

###### 2. Retrieve a Specific Profile
- **URL:** `/perfiles/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific user profile.
- **Parameters:**
  - `codigo` (required): Identifier for the profile.
  - `delegacion` (optional): Delegation code associated with the profile.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Admin Profile",
    "estado_desde": 0,
    "estado_hasta": 7,
    "precios_restringidos": "T",
    "campos_operaciones": "ALL",
    "campos_resultados": "ALL",
    "tipo_firma_delegacion": "DEL001",
    "tipo_firma_codigo": 2
  }
  ```

###### 3. Create a New Profile
- **URL:** `/perfiles`
- **Method:** `POST`
- **Description:** Creates a new user profile.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Admin Profile",
    "estado_desde": 0,
    "estado_hasta": 7,
    "precios_restringidos": "T",
    "campos_operaciones": "ALL",
    "campos_resultados": "ALL",
    "tipo_firma_delegacion": "DEL001",
    "tipo_firma_codigo": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Profile
- **URL:** `/perfiles/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing user profile.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Profile",
    "estado_desde": 1,
    "estado_hasta": 6,
    "precios_restringidos": "F",
    "campos_operaciones": "LIMITED",
    "campos_resultados": "ALL",
    "tipo_firma_delegacion": "DEL002",
    "tipo_firma_codigo": 3
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Profile
- **URL:** `/perfiles/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a user profile from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the profile.
  - `delegacion` (optional): Delegation code associated with the profile.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for User Profiles
The following fields are supported for user profiles:

| **Field**                 | **Type**   | **Description**                                                                     |
|---------------------------|------------|-------------------------------------------------------------------------------------|
| `delegacion`              | `string`   | Delegation code associated with the profile.                                        |
| `codigo`                  | `integer`  | Unique identifier for the profile.                                                  |
| `descripcion`             | `string`   | Description or name of the profile.                                                 |
| `estado_desde`            | `integer`  | Minimum state the profile can access (0-7).                                         |
| `estado_hasta`            | `integer`  | Maximum state the profile can access (0-7).                                         |
| `precios_restringidos`    | `string`   | Indicates if price access is restricted (`T` for true, `F` for false).              |
| `campos_operaciones`      | `string`   | Fields related to operations accessible by the profile.                             |
| `campos_resultados`       | `string`   | Fields related to results accessible by the profile.                                |
| `tipo_firma_delegacion`   | `string`   | Delegation code for the signature type associated with the profile.                 |
| `tipo_firma_codigo`       | `integer`  | Code for the signature type associated with the profile.                            |

##### Validation Rules for User Profiles
The following validation rules apply when creating or updating user profiles:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:50`
- `estado_desde`: `nullable|integer|in:0,1,2,3,4,5,6,7|`
- `estado_hasta`: `nullable|integer|in:0,1,2,3,4,5,6,7|`
- `precios_restringidos`: `nullable|string|in:T,F|max:1`
- `campos_operaciones`: `nullable|string`
- `campos_resultados`: `nullable|string`
- `tipo_firma_delegacion`: `nullable|string|max:10`
- `tipo_firma_codigo`: `nullable|integer`

#### Audits

##### Introduction
The Audits API provides endpoints to retrieve and manage audit records in the system. This section allows retrieving, viewing, and deleting audit records for tracking changes.

##### Available Endpoints

###### 1. List Audits
- **URL:** `/auditorias`
- **Method:** `GET`
- **Description:** Retrieves a list of all audit records.
- **Example Response:**
  ```json
  [
    {
      "codigo": "A001",
      "fecha": "2023-11-01",
      "tipo": "Modification",
      "tabla": "Users",
      "fila": "1",
      "campo": "Name",
      "valor_modificado": "John",
      "valor_anterior": "Jon",
      "sesion_codigo": "S001",
      "delegacion": "D001"
    }
  ]
  ```

###### 2. Retrieve Audit Details
- **URL:** `/auditorias/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information for a specific audit record.
- **Parameters:**
  - `codigo` (required): Identifier for the audit record.
  - `delegacion` (optional): Delegation code for filtering the audit record.
- **Example Response:**
  ```json
  {
    "codigo": "A001",
    "fecha": "2023-11-01",
    "tipo": "Modification",
    "tabla": "Users",
    "fila": "1",
    "campo": "Name",
    "valor_modificado": "John",
    "valor_anterior": "Jon",
    "sesion_codigo": "S001",
    "delegacion": "D001"
  }
  ```

###### 3. Delete Audit Record
- **URL:** `/auditorias/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific audit record from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the audit record.
  - `delegacion` (optional): Delegation code for filtering the audit record.
- **Example Response:**
  ```json
  {
    "message": "Registro borrado correctamente"
  }
  ```

##### Available Fields for Audits
The following fields are supported for the Audits API:

| **Field**              | **Type**   | **Description**                                |
|------------------------|------------|------------------------------------------------|
| `codigo`               | `string`   | Identifier for the audit record.               |
| `fecha`                | `date`     | Date when the audit was logged.                |
| `tipo`                 | `string`   | Type of audit (e.g., Modification, Deletion).  |
| `tabla`                | `string`   | Table name associated with the audit.          |
| `fila`                 | `string`   | Row identifier associated with the audit.      |
| `campo`                | `string`   | Field name associated with the audit.          |
| `valor_modificado`     | `string`   | Modified value in the audit record.            |
| `valor_anterior`       | `string`   | Previous value in the audit record.            |
| `sesion_codigo`        | `string`   | Session identifier linked to the audit.        |
| `delegacion`           | `string`   | Delegation code linked to the audit.           |

##### Validation Rules for Audits
The Audits API does not support creation or updates, so no validation rules are applied.

#### Archived Audits

##### Introduction
The Archived Audits API provides endpoints to retrieve and manage archived audit records. These endpoints allow for viewing and deleting archived audit logs.

##### Available Endpoints

###### 1. List Archived Audits
- **URL:** `/auditorias-archivadas`
- **Method:** `GET`
- **Description:** Retrieves a list of all archived audit records.
- **Example Response:**
  ```json
  [
    {
      "codigo": "AA001",
      "fecha": "2023-12-01",
      "tipo": "Modification",
      "tabla": "Users",
      "fila": "1",
      "campo": "Name",
      "valor_modificado": "John",
      "valor_anterior": "Jon",
      "sesion_codigo": "S001",
      "delegacion": "D001"
    }
  ]
  ```

###### 2. Retrieve Archived Audit Details
- **URL:** `/auditorias-archivadas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific archived audit record.
- **Parameters:**
  - `codigo` (required): Identifier for the archived audit record.
  - `delegacion` (optional): Delegation code for filtering the audit record.
- **Example Response:**
  ```json
  {
    "codigo": "AA001",
    "fecha": "2023-12-01",
    "tipo": "Modification",
    "tabla": "Users",
    "fila": "1",
    "campo": "Name",
    "valor_modificado": "John",
    "valor_anterior": "Jon",
    "sesion_codigo": "S001",
    "delegacion": "D001"
  }
  ```

###### 3. Delete Archived Audit Record
- **URL:** `/auditorias-archivadas/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific archived audit record from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the archived audit record.
  - `delegacion` (optional): Delegation code for filtering the audit record.
- **Example Response:**
  ```json
  {
    "message": "Registro borrado correctamente"
  }
  ```

##### Available Fields for Archived Audits
The following fields are supported for the Archived Audits API:

| **Field**              | **Type**   | **Description**                                |
|------------------------|------------|------------------------------------------------|
| `codigo`               | `string`   | Identifier for the archived audit record.      |
| `fecha`                | `date`     | Date when the archived audit was logged.       |
| `tipo`                 | `string`   | Type of archived audit (e.g., Modification).   |
| `tabla`                | `string`   | Table name associated with the audit.          |
| `fila`                 | `string`   | Row identifier associated with the audit.      |
| `campo`                | `string`   | Field name associated with the audit.          |
| `valor_modificado`     | `string`   | Modified value in the archived audit record.   |
| `valor_anterior`       | `string`   | Previous value in the archived audit record.   |
| `sesion_codigo`        | `string`   | Session identifier linked to the archived audit. |
| `delegacion`           | `string`   | Delegation code linked to the archived audit.  |

##### Validation Rules for Archived Audits
The Archived Audits API does not support creation or updates, so no validation rules are applied.

#### Services

##### Introduction
The Services API provides endpoints to manage services within the system. These endpoints allow for creating, retrieving, updating, and deleting service records.

##### Available Endpoints

###### 1. List Services
- **URL:** `/servicios`
- **Method:** `GET`
- **Description:** Retrieves a list of all services.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "SER001",
      "nombre": "Water Analysis",
      "nombre_informes": "WATER_ANALYSIS",
      "id_igeo": "IGE001",
      "descripcion": "Analysis of water samples for contaminants.",
      "observaciones": "Priority service.",
      "objetivo": "Ensure compliance with water quality standards.",
      "numero_envases": 5,
      "cantidad": "2L",
      "precio": 100.0,
      "descuento": "10%",
      "tiempo_prueba": 3,
      "tipo_dia": "L",
      "es_titulo_unico": "T",
      "fecha_baja": null,
      "es_baja": "F",
      "tipo_operacion_delegacion": "DEL002",
      "tipo_operacion_codigo": 1,
      "matriz_delegacion": "MAT001",
      "matriz_codigo": 10,
      "normativa_delegacion": "NOR001",
      "normativa_codigo": "NOR10"
    }
  ]
  ```

###### 2. Retrieve a Specific Service
- **URL:** `/servicios/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "SER001",
    "nombre": "Water Analysis",
    "nombre_informes": "WATER_ANALYSIS",
    "id_igeo": "IGE001",
    "descripcion": "Analysis of water samples for contaminants.",
    "observaciones": "Priority service.",
    "objetivo": "Ensure compliance with water quality standards.",
    "numero_envases": 5,
    "cantidad": "2L",
    "precio": 100.0,
    "descuento": "10%",
    "tiempo_prueba": 3,
    "tipo_dia": "L",
    "es_titulo_unico": "T",
    "fecha_baja": null,
    "es_baja": "F",
    "tipo_operacion_delegacion": "DEL002",
    "tipo_operacion_codigo": 1,
    "matriz_delegacion": "MAT001",
    "matriz_codigo": 10,
    "normativa_delegacion": "NOR001",
    "normativa_codigo": "NOR10"
  }
  ```

###### 3. Create a New Service
- **URL:** `/servicios`
- **Method:** `POST`
- **Description:** Creates a new service.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "SER002",
    "nombre": "Soil Analysis",
    "nombre_informes": "SOIL_ANALYSIS",
    "id_igeo": "IGE002",
    "descripcion": "Analysis of soil samples for contaminants.",
    "observaciones": "Requires specific containers.",
    "objetivo": "Determine soil quality for agricultural use.",
    "numero_envases": 3,
    "cantidad": "5Kg",
    "precio": 200.0,
    "descuento": "5%",
    "tiempo_prueba": 5,
    "tipo_dia": "N",
    "es_titulo_unico": "F",
    "tipo_operacion_delegacion": "DEL003",
    "tipo_operacion_codigo": 2,
    "matriz_delegacion": "MAT002",
    "matriz_codigo": 20,
    "normativa_delegacion": "NOR002",
    "normativa_codigo": "NOR20"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "SER002",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Service
- **URL:** `/servicios/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing service.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Water Analysis",
    "descripcion": "Updated description for water analysis.",
    "precio": 120.0,
    "tiempo_prueba": 4
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Service
- **URL:** `/servicios/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Services
The following fields are supported for services:

| **Field**                   | **Type**   | **Description**                                                                     |
|-----------------------------|------------|-------------------------------------------------------------------------------------|
| `delegacion`                | `string`   | Delegation code associated with the service.                                       |
| `codigo`                    | `string`   | Unique identifier for the service.                                                 |
| `nombre`                    | `string`   | Name of the service.                                                               |
| `nombre_informes`           | `string`   | Report name associated with the service.                                           |
| `id_igeo`                   | `string`   | Geographic identifier for the service.                                             |
| `descripcion`               | `string`   | Description of the service.                                                        |
| `observaciones`             | `string`   | Observations about the service.                                                    |
| `objetivo`                  | `string`   | Objective of the service.                                                          |
| `numero_envases`            | `numeric`  | Number of containers required for the service.                                     |
| `cantidad`                  | `string`   | Quantity associated with the service.                                              |
| `precio`                    | `numeric`  | Price of the service.                                                              |
| `descuento`                 | `string`   | Discount applicable to the service.                                                |
| `tiempo_prueba`             | `integer`  | Test time in days.                                                                 |
| `tipo_dia`                  | `string`   | Type of day (`L` for business days, `N` for natural days).                         |
| `es_titulo_unico`           | `string`   | Indicates if the service is unique (`T` for true, `F` for false).                  |
| `fecha_baja`                | `date`     | Deactivation date of the service.                                                  |
| `es_baja`                   | `string`   | Indicates if the service is inactive (`T` for true, `F` for false).                |
| `tipo_operacion_delegacion` | `string`   | Delegation code for the operation type associated with the service.                |
| `tipo_operacion_codigo`     | `integer`  | Code for the operation type associated with the service.                           |
| `matriz_delegacion`         | `string`   | Delegation code for the matrix associated with the service.                        |
| `matriz_codigo`             | `integer`  | Code for the matrix associated with the service.                                   |
| `normativa_delegacion`      | `string`   | Delegation code for the regulations associated with the service.                   |
| `normativa_codigo`          | `string`   | Code for the regulations associated with the service.                              |

##### Validation Rules for Services
The following validation rules apply when creating or updating services:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:20`
- `nombre`: `nullable|string|max:100`
- `nombre_informes`: `nullable|string|max:100`
- `id_igeo`: `nullable|string|max:20`
- `descripcion`: `nullable|string`
- `observaciones`: `nullable|string`
- `objetivo`: `nullable|string`
- `numero_envases`: `nullable|numeric`
- `cantidad`: `nullable|string|max:50`
- `precio`: `nullable|numeric`
- `descuento`: `nullable|string|max:15`
- `tiempo_prueba`: `nullable|integer`
- `tipo_dia`: `nullable|string|in:L,N|max:1`
- `es_titulo_unico`: `nullable|string|in:T,F|max:1`
- `fecha_baja`: `nullable|date`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `tipo_operacion_delegacion`: `nullable|string|max:10`
- `tipo_operacion_codigo`: `nullable|integer`
- `matriz_delegacion`: `nullable|string|max:10`
- `matriz_codigo`: `nullable|integer`
- `normativa_delegacion`: `nullable|string|max:10`
- `normativa_codigo`: `nullable|string|max:20`

#### Service Parameters

##### Introduction
The Service Parameters API provides endpoints to manage parameters associated with services. These endpoints allow adding, retrieving, updating, and deleting service parameter records.

##### Available Endpoints

###### 1. List Service Parameters
- **URL:** `/servicios-parametros/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of parameters linked to a specific service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code for the service.
- **Example Response:**
  ```json
  [
    {
      "servicio_delegacion": "DEL001",
      "servicio_codigo": "SVC001",
      "parametro_delegacion": "DEL002",
      "parametro_codigo": "PRM001",
      "posicion": 1
    }
  ]
  ```

###### 2. Retrieve a Specific Parameter
- **URL:** `/servicios-parametros/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific parameter associated with a service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code for the service.
  - `clave1` (required): Identifier for the parameter.
  - `clave2` (optional): Delegation code for the parameter.
- **Example Response:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "SVC001",
    "parametro_delegacion": "DEL002",
    "parametro_codigo": "PRM001",
    "posicion": 1
  }
  ```

###### 3. Add a Parameter to a Service
- **URL:** `/servicios-parametros`
- **Method:** `POST`
- **Description:** Adds a parameter to a service.
- **Request Body:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "SVC001",
    "parametro_delegacion": "DEL002",
    "parametro_codigo": "PRM001",
    "posicion": 1
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "servicio_codigo": "SVC001",
      "parametro_codigo": "PRM001"
    }
  }
  ```

###### 4. Update a Parameter
- **URL:** `/servicios-parametros/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates a specific parameter associated with a service.
- **Request Body:**
  ```json
  {
    "posicion": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Parameter
- **URL:** `/servicios-parametros/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a parameter from a service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code for the service.
  - `clave1` (required): Identifier for the parameter.
  - `clave2` (optional): Delegation code for the parameter.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Service Parameters
The following fields are supported for service parameters:

| **Field**              | **Type**   | **Description**                                     |
|------------------------|------------|-----------------------------------------------------|
| `servicio_delegacion`  | `string`   | Delegation code for the service.                    |
| `servicio_codigo`      | `string`   | Unique identifier for the service.                  |
| `parametro_delegacion` | `string`   | Delegation code for the parameter.                  |
| `parametro_codigo`     | `string`   | Unique identifier for the parameter.                |
| `posicion`             | `integer`  | Position of the parameter in the service.           |

##### Validation Rules for Service Parameters
The following validation rules apply when creating or updating service parameters:

- `servicio_delegacion`: `nullable|string|max:10`
- `servicio_codigo`: `required|string|max:20`
- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `posicion`: `nullable|integer|min:1`

#### Service Expenses

##### Introduction
The Service Expenses API provides endpoints to manage expenses associated with services. These endpoints allow adding, retrieving, and deleting service-related expense records.

##### Available Endpoints

###### 1. List Expenses for a Service
- **URL:** `/servicios-gastos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of expenses associated with a specific service.
- **Parameters:**
  - `codigo` (required): Service identifier.
  - `delegacion` (optional): Delegation code associated with the service.
- **Example Response:**
  ```json
  [
    {
      "servicio_delegacion": "DEL001",
      "servicio_codigo": "SER001",
      "gasto_delegacion": "DEL002",
      "gasto_codigo": "ESC001"
    }
  ]
  ```

###### 2. Retrieve Expense Details for a Service
- **URL:** `/servicios-gastos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific expense for a service.
- **Parameters:**
  - `codigo` (required): Service identifier.
  - `delegacion` (optional): Delegation code associated with the service.
  - `clave1` (required): Expense identifier.
  - `clave2` (optional): Delegation code for the expense.
- **Example Response:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "SER001",
    "gasto_delegacion": "DEL002",
    "gasto_codigo": "ESC001"
  }
  ```

###### 3. Add a New Expense to a Service
- **URL:** `/servicios-gastos`
- **Method:** `POST`
- **Description:** Adds a new expense record for a service.
- **Request Body:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "SER001",
    "gasto_delegacion": "DEL002",
    "gasto_codigo": "ESC001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "servicio_codigo": "SER001",
      "servicio_delegacion": "DEL001",
      "gasto_codigo": "ESC001",
      "gasto_delegacion": "DEL002"
    }
  }
  ```

###### 4. Remove an Expense from a Service
- **URL:** `/servicios-gastos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes an expense record from a service.
- **Parameters:**
  - `codigo` (required): Service identifier.
  - `delegacion` (optional): Delegation code associated with the service.
  - `clave1` (required): Expense identifier.
  - `clave2` (optional): Delegation code for the expense.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Service Expenses
The following fields are supported for the Service Expenses API:

| **Field**             | **Type**   | **Description**                              |
|-----------------------|------------|----------------------------------------------|
| `servicio_delegacion` | `string`   | Delegation code associated with the service. |
| `servicio_codigo`     | `string`   | Unique identifier for the service.           |
| `gasto_delegacion`    | `string`   | Delegation code associated with the expense. |
| `gasto_codigo`        | `integer`  | Unique identifier for the expense.           |

##### Validation Rules for Service Expenses
The following validation rules apply when creating or updating service expenses:

- `servicio_delegacion`: `nullable|string|max:10`
- `servicio_codigo`: `required|string|max:20`
- `gasto_delegacion`: `nullable|string|max:10`
- `gasto_codigo`: `required|integer`

#### Service Client Prices

##### Introduction
The Service Client Prices provides endpoints to manage specific service pricing for individual clients. These endpoints allow you to retrieve, add, update, and delete client-specific service prices.

##### Available Endpoints

###### 1. List Client Prices for a Service
- **URL:** `/servicios-precios-clientes/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of client-specific prices for a given service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
- **Example Response:**
  ```json
  [
    {
      "servicio_delegacion": "DEL001",
      "servicio_codigo": "SER001",
      "cliente_delegacion": "CLI001",
      "cliente_codigo": "C001",
      "precio": 150.50,
      "descuento": "10%"
    }
  ]
  ```

###### 2. Retrieve a Specific Client Price
- **URL:** `/servicios-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific client price for a service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
  - `clave1` (required): Client identifier.
  - `clave2` (optional): Delegation code for the client.
- **Example Response:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "SER001",
    "cliente_delegacion": "CLI001",
    "cliente_codigo": "C001",
    "precio": 150.50,
    "descuento": "10%"
  }
  ```

###### 3. Create a Client Price for a Service
- **URL:** `/servicios-precios-clientes`
- **Method:** `POST`
- **Description:** Creates a new client-specific price for a service.
- **Request Body:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "SER001",
    "cliente_delegacion": "CLI001",
    "cliente_codigo": "C001",
    "precio": 150.50,
    "descuento": "10%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "servicio_codigo": "SER001",
      "servicio_delegacion": "DEL001",
      "cliente_codigo": "C001",
      "cliente_delegacion": "CLI001"
    }
  }
  ```

###### 4. Update a Client Price for a Service
- **URL:** `/servicios-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing client-specific price for a service.
- **Request Body:**
  ```json
  {
    "precio": 160.00,
    "descuento": "15%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Client Price for a Service
- **URL:** `/servicios-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific client-specific price for a service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
  - `clave1` (required): Client identifier.
  - `clave2` (optional): Delegation code for the client.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Service Client Prices
The following fields are supported for service client prices:

| **Field**               | **Type**   | **Description**                                    |
|-------------------------|------------|----------------------------------------------------|
| `servicio_delegacion`   | `string`   | Delegation code associated with the service.       |
| `servicio_codigo`       | `string`   | Identifier for the service.                        |
| `cliente_delegacion`    | `string`   | Delegation code associated with the client.        |
| `cliente_codigo`        | `string`   | Identifier for the client.                         |
| `precio`                | `numeric`  | Price assigned to the client for the service.      |
| `descuento`             | `string`   | Discount applied to the client price.              |

##### Validation Rules for Service Client Prices
The following validation rules apply when creating or updating service client prices:

- `servicio_delegacion`: `nullable|string|max:10`
- `servicio_codigo`: `required|string|max:20`
- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `required|string|max:20`
- `precio`: `nullable|numeric|min:0`
- `descuento`: `nullable|string|max:15`

#### Service Tariff Prices

##### Introduction
The Service Tariff Prices API provides endpoints to manage the pricing of services based on tariffs. These endpoints allow for creating, retrieving, updating, and deleting tariff-specific pricing records for services.

##### Available Endpoints

###### 1. List Tariff Prices for a Service
- **URL:** `/servicios-precios-tarifas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of tariff-based prices for a specific service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
- **Example Response:**
  ```json
  [
    {
      "servicio_delegacion": "DEL001",
      "servicio_codigo": "S001",
      "tarifa_delegacion": "TAR001",
      "tarifa_codigo": 1001,
      "precio": 150.0,
      "descuento": "10%"
    }
  ]
  ```

###### 2. Retrieve a Specific Tariff Price
- **URL:** `/servicios-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific tariff price for a service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
  - `clave1` (required): Identifier for the tariff.
  - `clave2` (optional): Delegation code for the tariff.
- **Example Response:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "S001",
    "tarifa_delegacion": "TAR001",
    "tarifa_codigo": 1001,
    "precio": 150.0,
    "descuento": "10%"
  }
  ```

###### 3. Create a New Tariff Price
- **URL:** `/servicios-precios-tarifas`
- **Method:** `POST`
- **Description:** Creates a new tariff price for a service.
- **Request Body:**
  ```json
  {
    "servicio_delegacion": "DEL001",
    "servicio_codigo": "S001",
    "tarifa_delegacion": "TAR001",
    "tarifa_codigo": 1001,
    "precio": 150.0,
    "descuento": "10%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "servicio_codigo": "S001",
      "servicio_delegacion": "DEL001",
      "tarifa_codigo": 1001,
      "tarifa_delegacion": "TAR001"
    }
  }
  ```

###### 4. Update a Tariff Price
- **URL:** `/servicios-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing tariff price for a service.
- **Request Body:**
  ```json
  {
    "precio": 175.0,
    "descuento": "5%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Tariff Price
- **URL:** `/servicios-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a tariff price for a specific service.
- **Parameters:**
  - `codigo` (required): Identifier for the service.
  - `delegacion` (optional): Delegation code associated with the service.
  - `clave1` (required): Identifier for the tariff.
  - `clave2` (optional): Delegation code for the tariff.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Service Tariff Prices
The following fields are supported for the Service Tariff Prices API:

| **Field**              | **Type**   | **Description**                                        |
|------------------------|------------|--------------------------------------------------------|
| `servicio_delegacion`  | `string`   | Delegation code associated with the service.           |
| `servicio_codigo`      | `string`   | Identifier for the service.                            |
| `tarifa_delegacion`    | `string`   | Delegation code associated with the tariff.            |
| `tarifa_codigo`        | `integer`  | Identifier for the tariff.                             |
| `precio`               | `numeric`  | Price assigned to the service for the specific tariff. |
| `descuento`            | `string`   | Discount applied to the service price.                 |

##### Validation Rules for Service Tariff Prices
The following validation rules apply when creating or updating tariff prices:

- `servicio_delegacion`: `nullable|string|max:10`
- `servicio_codigo`: `required|string|max:20`
- `tarifa_delegacion`: `nullable|string|max:10`
- `tarifa_codigo`: `required|integer`
- `precio`: `nullable|numeric`
- `descuento`: `nullable|string|max:15`

#### Parameters

##### Introduction
The Parameters API provides endpoints to manage parameters within the system. These endpoints allow for creating, retrieving, updating, and deleting parameter records.

##### Available Endpoints

###### 1. List Parameters
- **URL:** `/parametros`
- **Method:** `GET`
- **Description:** Retrieves a list of all parameters.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "TEC001",
      "nombre": "pH",
      "nombre_informes": "Acidity",
      "id_igeo": "12345",
      "es_cursiva": "F",
      "fecha_acreditacion": "2023-10-01",
      "parametro": "Standard pH",
      "abreviatura": "PH",
      "numero_cas": "123-45-6",
      "precio": 50.00,
      "descuento": "10%",
      "unidades": "pH units",
      "leyenda": "N/A",
      "metodologia": "ISO 12345",
      "normativa": "EU Standard",
      "tiempo_prueba": 24,
      "es_exportable": "T"
    }
  ]
  ```

###### 2. Retrieve a Specific Parameter
- **URL:** `/parametros/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "TEC001",
    "nombre": "pH",
    "nombre_informes": "Acidity",
    "id_igeo": "12345",
    "es_cursiva": "F",
    "fecha_acreditacion": "2023-10-01",
    "parametro": "Standard pH",
    "abreviatura": "PH",
    "numero_cas": "123-45-6",
    "precio": 50.00,
    "descuento": "10%",
    "unidades": "pH units",
    "leyenda": "N/A",
    "metodologia": "ISO 12345",
    "normativa": "EU Standard",
    "tiempo_prueba": 24,
    "es_exportable": "T"
  }
  ```

###### 3. Create a New Parameter
- **URL:** `/parametros`
- **Method:** `POST`
- **Description:** Creates a new parameter.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "TEC002",
    "nombre": "Conductivity",
    "precio": 100.00,
    "tiempo_prueba": 48
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "TEC002",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Parameter
- **URL:** `/parametros/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing parameter.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Conductivity",
    "precio": 120.00
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Parameter
- **URL:** `/parametros/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameters
The following fields are supported for parameters:

| **Field**                       | **Type**   | **Description**                                                       |
|---------------------------------|------------|-----------------------------------------------------------------------|
| `delegacion`                    | `string`   | Delegation code associated with the parameter.                        |
| `codigo`                        | `string`   | Unique identifier for the parameter.                                  |
| `nombre`                        | `string`   | Name of the parameter.                                                |
| `nombre_informes`               | `string`   | Report name for the parameter.                                        |
| `id_igeo`                       | `string`   | IGEO identifier for the parameter.                                    |
| `es_cursiva`                    | `string`   | Indicates if the parameter is italicized (`T` for true, `F` for false).|
| `fecha_acreditacion`            | `date`     | Accreditation date for the parameter.                                 |
| `parametro`                     | `string`   | Parameter value or description.                                       |
| `abreviatura`                   | `string`   | Abbreviation for the parameter.                                       |
| `numero_cas`                    | `string`   | CAS number for the parameter.                                         |
| `precio`                        | `numeric`  | Price of the parameter.                                               |
| `descuento`                     | `string`   | Discount applied to the parameter.                                    |
| `unidades`                      | `string`   | Units of measurement for the parameter.                               |
| `leyenda`                       | `string`   | Legend or additional information about the parameter.                 |
| `metodologia`                   | `string`   | Methodology used for the parameter.                                   |
| `metodologia_abreviada`         | `string`   | Abbreviated methodology description.                                  |
| `normativa`                     | `string`   | Normative or standard followed.                                       |
| `tiempo_prueba`                 | `integer`  | Time required for testing (in hours).                                 |
| `tiempo_descarte`               | `integer`  | Time required for discarding (in hours).                              |
| `limite_cuantificacion`         | `string`   | Quantification limit for the parameter.                               |
| `valor_minimo_detectable`       | `string`   | Minimum detectable value for the parameter.                           |
| `incertidumbre`                 | `string`   | Uncertainty value for the parameter.                                  |
| `instruccion`                   | `string`   | Instructions related to the parameter.                                |
| `es_exportable`                 | `string`   | Indicates if the parameter is exportable (`T` for true, `F` for false).|
| `codigo_metodo_sinac`           | `integer`  | SINAC method code.                                                    |
| `tipo_metodo_sinac`             | `integer`  | SINAC method type.                                                    |
| `numero_norma_sinac`            | `string`   | SINAC normative number.                                               |
| `es_acreditado_sinac`           | `string`   | Indicates if the parameter is SINAC-accredited (`T` or `F`).          |
| `es_validado_sinac`             | `string`   | Indicates if the parameter is SINAC-validated (`T` or `F`).           |
| `es_equivalente_sinac`          | `string`   | Indicates if the parameter is SINAC-equivalent (`T` or `F`).          |
| `es_sin_cualificacion_sinac`    | `string`   | Indicates if the parameter lacks SINAC qualifications (`T` or `F`).   |
| `es_uso_rutina_sinac`           | `string`   | Indicates if the parameter is for routine use in SINAC (`T` or `F`).  |
| `codigo_parametro_sinac`        | `string`   | SINAC parameter code.                                                 |
| `exactitud_sinac`               | `numeric`  | SINAC exactitude value.                                               |
| `precision_sinac`               | `numeric`  | SINAC precision value.                                                |
| `limite_deteccion_sinac`        | `numeric`  | SINAC detection limit.                                                |
| `limite_cuantificacion_sinac`   | `numeric`  | SINAC quantification limit.                                            |
| `codigo_laboratorio_sinac`      | `numeric`  | SINAC laboratory code.                                                |
| `decimales_sinac`               | `integer`  | Number of decimals for SINAC values.                                  |
| `fecha_baja`                    | `date`     | Deactivation date for the parameter.                                  |
| `es_baja`                       | `string`   | Indicates if the parameter is deactivated (`T` or `F`).               |
| `seccion_delegacion`            | `string`   | Delegation code for the parameter's section.                          |
| `seccion_codigo`                | `integer`  | Code for the parameter's section.                                     |

##### Validation Rules for Parameters
The following validation rules apply when creating or updating parameters:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:30`
- `nombre`: `nullable|string|max:255`
- `nombre_informes`: `nullable|string|max:255`
- `id_igeo`: `nullable|string|max:20`
- `es_cursiva`: `nullable|string|in:T,F|max:1`
- `fecha_acreditacion`: `nullable|date`
- `parametro`: `nullable|string|max:100`
- `abreviatura`: `nullable|string|max:50`
- `numero_cas`: `nullable|string|max:50`
- `precio`: `nullable|numeric|min:0`
- `descuento`: `nullable|string|max:15`
- `unidades`: `nullable|string|max:50`
- `leyenda`: `nullable|string|max:100`
- `metodologia`: `nullable|string|max:255`
- `metodologia_abreviada`: `nullable|string|max:255`
- `normativa`: `nullable|string|max:100`
- `tiempo_prueba`: `nullable|integer|min:0`
- `tiempo_descarte`: `nullable|integer|min:0`
- `limite_cuantificacion`: `nullable|string|max:50`
- `valor_minimo_detectable`: `nullable|string|max:50`
- `incertidumbre`: `nullable|string|max:50`
- `instruccion`: `nullable|string`
- `es_exportable`: `nullable|string|in:T,F|max:1`
- `codigo_metodo_sinac`: `nullable|integer|min:0`
- `tipo_metodo_sinac`: `nullable|integer|min:0`
- `numero_norma_sinac`: `nullable|string|max:50`
- `es_acreditado_sinac`: `nullable|string|in:T,F|max:1`
- `es_validado_sinac`: `nullable|string|in:T,F|max:1`
- `es_equivalente_sinac`: `nullable|string|in:T,F|max:1`
- `es_sin_cualificacion_sinac`: `nullable|string|in:T,F|max:1`
- `es_uso_rutina_sinac`: `nullable|string|in:T,F|max:1`
- `codigo_parametro_sinac`: `nullable|string|max:10`
- `exactitud_sinac`: `nullable|numeric|min:0`
- `precision_sinac`: `nullable|numeric|min:0`
- `limite_deteccion_sinac`: `nullable|numeric|min:0`
- `limite_cuantificacion_sinac`: `nullable|numeric|min:0`
- `codigo_laboratorio_sinac`: `nullable|numeric|min:0`
- `decimales_sinac`: `nullable|integer|min:0`
- `fecha_baja`: `nullable|date`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `seccion_delegacion`: `nullable|string|max:10`
- `seccion_codigo`: `nullable|integer|min:0`

#### Parameter Normative Values

##### Introduction
The Parameter Normative Values API provides endpoints to manage the association between parameters and their normative values, including creation, retrieval, updating, and deletion of these relationships.

##### Available Endpoints

###### 1. List Normative Values for a Parameter
- **URL:** `/parametros-normativas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves all normative values associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "normativa_delegacion": "NOR001",
      "normativa_codigo": "N001",
      "valor": "10.5",
      "rango": "5-15"
    }
  ]
  ```

###### 2. Retrieve Normative Value Details
- **URL:** `/parametros-normativas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific normative value for a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Normative code.
  - `clave2` (optional): Delegation code for the normative.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "normativa_delegacion": "NOR001",
    "normativa_codigo": "N001",
    "valor": "10.5",
    "rango": "5-15"
  }
  ```

###### 3. Create a New Normative Value
- **URL:** `/parametros-normativas`
- **Method:** `POST`
- **Description:** Associates a new normative value with a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "normativa_delegacion": "NOR001",
    "normativa_codigo": "N001",
    "valor": "10.5",
    "rango": "5-15"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "normativa_codigo": "N001"
    }
  }
  ```

###### 4. Update a Normative Value
- **URL:** `/parametros-normativas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing normative value for a parameter.
- **Request Body:**
  ```json
  {
    "valor": "12.0",
    "rango": "10-20"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Normative Value
- **URL:** `/parametros-normativas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a normative value associated with a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Normative code.
  - `clave2` (optional): Delegation code for the normative.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameter Normative Values
The following fields are supported for this API:

| **Field**               | **Type**   | **Description**                           |
|-------------------------|------------|-------------------------------------------|
| `parametro_delegacion`  | `string`   | Delegation code of the parameter.         |
| `parametro_codigo`      | `string`   | Code of the parameter.                    |
| `normativa_delegacion`  | `string`   | Delegation code of the normative.         |
| `normativa_codigo`      | `string`   | Code of the normative.                    |
| `valor`                 | `string`   | Normative value.                          |
| `rango`                 | `string`   | Normative range.                          |

##### Validation Rules for Parameter Normative Values
The following validation rules apply when creating or updating normative values:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `normativa_delegacion`: `nullable|string|max:10`
- `normativa_codigo`: `required|string|max:20`
- `valor`: `nullable|string|max:100`
- `rango`: `nullable|string|max:100`

#### Parameter Matrices

##### Introduction
The Parameter Matrices API provides endpoints to manage the associations between parameters and matrices. These endpoints allow listing, retrieving, creating, and deleting these associations.

##### Available Endpoints

###### 1. List Parameter Matrices
- **URL:** `/parametros-matrices/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of matrices associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "matriz_delegacion": "DEL002",
      "matriz_codigo": "M001"
    }
  ]
  ```

###### 2. Retrieve Parameter Matrix Details
- **URL:** `/parametros-matrices/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific parameter-matrix association.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Identifier for the matrix.
  - `clave2` (optional): Delegation code for the matrix.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "matriz_delegacion": "DEL002",
    "matriz_codigo": "M001"
  }
  ```

###### 3. Associate a Matrix with a Parameter
- **URL:** `/parametros-matrices`
- **Method:** `POST`
- **Description:** Creates a new association between a parameter and a matrix.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "matriz_delegacion": "DEL002",
    "matriz_codigo": "M001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "parametro_delegacion": "DEL001",
      "matriz_codigo": "M001",
      "matriz_delegacion": "DEL002"
    }
  }
  ```

###### 4. Remove a Parameter-Matrix Association
- **URL:** `/parametros-matrices/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific parameter-matrix association.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Identifier for the matrix.
  - `clave2` (optional): Delegation code for the matrix.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameter Matrices
The following fields are supported for parameter matrices:

| **Field**             | **Type**   | **Description**                                |
|-----------------------|------------|------------------------------------------------|
| `parametro_delegacion`| `string`   | Delegation code for the parameter.             |
| `parametro_codigo`    | `string`   | Identifier for the parameter.                  |
| `matriz_delegacion`   | `string`   | Delegation code for the matrix.                |
| `matriz_codigo`       | `integer`  | Identifier for the matrix.                     |

##### Validation Rules for Parameter Matrices
The following validation rules apply when creating or updating parameter-matrix associations:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `matriz_delegacion`: `nullable|string|max:10`
- `matriz_codigo`: `required|integer`

#### Parameter Client Prices

##### Introduction
The Parameter Client Prices API provides endpoints to manage custom prices for parameters based on specific clients. These endpoints allow listing, retrieving, creating, updating, and deleting parameter prices for clients.

##### Available Endpoints

###### 1. List Parameter Prices for Clients
- **URL:** `/parametros-precios-clientes/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of client-specific prices for a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "cliente_delegacion": "CLI001",
      "cliente_codigo": "C001",
      "precio": 50.00,
      "descuento": "10%"
    }
  ]
  ```

###### 2. Retrieve Specific Parameter Price for a Client
- **URL:** `/parametros-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves the price details for a specific parameter assigned to a client.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Client code.
  - `clave2` (optional): Client delegation code.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "cliente_delegacion": "CLI001",
    "cliente_codigo": "C001",
    "precio": 50.00,
    "descuento": "10%"
  }
  ```

###### 3. Create a Client-Specific Parameter Price
- **URL:** `/parametros-precios-clientes`
- **Method:** `POST`
- **Description:** Creates a new client-specific price for a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "cliente_delegacion": "CLI001",
    "cliente_codigo": "C001",
    "precio": 50.00,
    "descuento": "10%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "cliente_codigo": "C001"
    }
  }
  ```

###### 4. Update a Client-Specific Parameter Price
- **URL:** `/parametros-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing client-specific price for a parameter.
- **Request Body:**
  ```json
  {
    "precio": 55.00,
    "descuento": "15%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Client-Specific Parameter Price
- **URL:** `/parametros-precios-clientes/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a client-specific price for a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Client code.
  - `clave2` (optional): Client delegation code.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameter Client Prices
The following fields are supported for parameter client prices:

| **Field**             | **Type**   | **Description**                                  |
|-----------------------|------------|--------------------------------------------------|
| `parametro_delegacion`| `string`   | Delegation code for the parameter.               |
| `parametro_codigo`    | `string`   | Identifier for the parameter.                    |
| `cliente_delegacion`  | `string`   | Delegation code for the client.                  |
| `cliente_codigo`      | `string`   | Identifier for the client.                       |
| `precio`              | `numeric`  | Custom price assigned to the parameter.          |
| `descuento`           | `string`   | Discount applied to the parameter price.         |

##### Validation Rules for Parameter Client Prices
The following validation rules apply when creating or updating parameter client prices:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `nullable|string|max:30`
- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `nullable|string|max:15`
- `precio`: `nullable|numeric`
- `descuento`: `nullable|string|max:15`

#### Parameters Prices by Rate

##### Introduction
The Parameters Prices by Rate API provides endpoints to manage parameter pricing based on predefined rates. These endpoints allow creating, retrieving, updating, and deleting pricing records linked to specific parameters and rates.

##### Available Endpoints

###### 1. List Parameter Prices by Rate
- **URL:** `/parametros-precios-tarifas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of all parameter prices associated with a specific rate.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "tarifa_delegacion": "TAR001",
      "tarifa_codigo": "T001",
      "precio": 150.50,
      "descuento": "10%"
    }
  ]
  ```

###### 2. Retrieve a Specific Parameter Price by Rate
- **URL:** `/parametros-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed pricing information for a specific parameter associated with a rate.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Rate code.
  - `clave2` (optional): Rate delegation code.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "tarifa_delegacion": "TAR001",
    "tarifa_codigo": "T001",
    "precio": 150.50,
    "descuento": "10%"
  }
  ```

###### 3. Create a New Parameter Price by Rate
- **URL:** `/parametros-precios-tarifas`
- **Method:** `POST`
- **Description:** Creates a new parameter price linked to a specific rate.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "tarifa_delegacion": "TAR001",
    "tarifa_codigo": "T001",
    "precio": 150.50,
    "descuento": "10%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "tarifa_codigo": "T001"
    }
  }
  ```

###### 4. Update a Parameter Price by Rate
- **URL:** `/parametros-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing parameter price linked to a specific rate.
- **Request Body:**
  ```json
  {
    "precio": 170.00,
    "descuento": "5%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Parameter Price by Rate
- **URL:** `/parametros-precios-tarifas/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a parameter price record linked to a specific rate.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Rate code.
  - `clave2` (optional): Rate delegation code.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameters Prices by Rate
The following fields are supported for parameter prices by rate:

| **Field**              | **Type**   | **Description**                                                |
|------------------------|------------|----------------------------------------------------------------|
| `parametro_delegacion` | `string`   | Delegation code associated with the parameter.                 |
| `parametro_codigo`     | `string`   | Code of the parameter.                                         |
| `tarifa_delegacion`    | `string`   | Delegation code associated with the rate.                      |
| `tarifa_codigo`        | `string`   | Code of the rate.                                              |
| `precio`               | `numeric`  | Price assigned to the parameter for the specific rate.         |
| `descuento`            | `string`   | Discount applied to the parameter price (e.g., `10%`).         |

##### Validation Rules for Parameters Prices by Rate
The following validation rules apply when creating or updating parameter prices by rate:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `tarifa_delegacion`: `nullable|string|max:10`
- `tarifa_codigo`: `required|integer`
- `precio`: `nullable|numeric`
- `descuento`: `nullable|string|max:15`

#### Qualified Personnel for Parameters

##### Introduction
The Qualified Personnel for Parameters API allows for managing the relationship between parameters and qualified personnel. These endpoints enable listing, retrieving, creating, updating, and deleting records.

##### Available Endpoints

###### 1. List Qualified Personnel for a Parameter
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of qualified personnel associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "empleado_delegacion": "EMP001",
      "empleado_codigo": "E001",
      "posicion": 1
    }
  ]
  ```

###### 2. Retrieve Qualified Personnel Details
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details about a specific qualified personnel associated with a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the personnel.
  - `clave2` (optional): Delegation code for the personnel.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001",
    "posicion": 1
  }
  ```

###### 3. Assign Qualified Personnel to a Parameter
- **URL:** `/parametros-empleados`
- **Method:** `POST`
- **Description:** Assigns qualified personnel to a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001",
    "posicion": 1
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "parametro_delegacion": "DEL001"
    }
  }
  ```

###### 4. Update Personnel Assignment
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing personnel assignment for a parameter.
- **Request Body:**
  ```json
  {
    "posicion": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Remove Personnel Assignment
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Removes a personnel assignment from a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the personnel.
  - `clave2` (optional): Delegation code for the personnel.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Qualified Personnel for Parameters
The following fields are supported for the Qualified Personnel for Parameters API:

| **Field**               | **Type**   | **Description**                                             |
|-------------------------|------------|-------------------------------------------------------------|
| `parametro_delegacion`  | `string`   | Delegation code associated with the parameter.              |
| `parametro_codigo`      | `string`   | Unique identifier for the parameter.                        |
| `empleado_delegacion`   | `string`   | Delegation code associated with the personnel.              |
| `empleado_codigo`       | `integer`  | Unique identifier for the personnel.                        |
| `posicion`              | `integer`  | Position of the personnel in relation to the parameter.     |

##### Validation Rules for Qualified Personnel for Parameters
The following validation rules apply when creating or updating records:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `required|integer`
- `posicion`: `nullable|integer|min:1`

#### Parameter Equipment

##### Introduction
The Parameter Equipment API provides endpoints to manage equipment associated with specific parameters. These endpoints allow for creating, retrieving, updating, and deleting parameter-equipment relationships.

##### Available Endpoints

###### 1. List Equipment for a Parameter
- **URL:** `/parametros-equipos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of equipment associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "TEC001",
      "producto_delegacion": "DEL002",
      "producto_codigo": "PRD001",
      "formato_importacion": 1,
      "nombre_importacion": "ImportFormat1",
      "columnas": "ColumnA, ColumnB"
    }
  ]
  ```

###### 2. Retrieve Equipment Details
- **URL:** `/parametros-equipos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific equipment associated with a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the equipment.
  - `clave2` (optional): Delegation code for the equipment.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "TEC001",
    "producto_delegacion": "DEL002",
    "producto_codigo": "PRD001",
    "formato_importacion": 1,
    "nombre_importacion": "ImportFormat1",
    "columnas": "ColumnA, ColumnB"
  }
  ```

###### 3. Associate Equipment with a Parameter
- **URL:** `/parametros-equipos`
- **Method:** `POST`
- **Description:** Associates new equipment with a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "TEC001",
    "producto_delegacion": "DEL002",
    "producto_codigo": "PRD001",
    "formato_importacion": 1,
    "nombre_importacion": "ImportFormat1",
    "columnas": "ColumnA, ColumnB"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "TEC001",
      "producto_codigo": "PRD001"
    }
  }
  ```

###### 4. Update Equipment Association
- **URL:** `/parametros-equipos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates the relationship between a parameter and equipment.
- **Request Body:**
  ```json
  {
    "formato_importacion": 2,
    "nombre_importacion": "UpdatedFormat",
    "columnas": "ColumnX, ColumnY"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Remove Equipment from a Parameter
- **URL:** `/parametros-equipos/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes the relationship between a parameter and specific equipment.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the equipment.
  - `clave2` (optional): Delegation code for the equipment.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameter Equipment
The following fields are supported for the Parameter Equipment API:

| **Field**              | **Type**   | **Description**                                                  |
|------------------------|------------|------------------------------------------------------------------|
| `parametro_delegacion` | `string`   | Delegation code associated with the parameter.                   |
| `parametro_codigo`     | `string`   | Identifier for the parameter.                                    |
| `producto_delegacion`  | `string`   | Delegation code associated with the equipment.                   |
| `producto_codigo`      | `string`   | Identifier for the equipment.                                    |
| `formato_importacion`  | `integer`  | Import format code for the equipment.                            |
| `nombre_importacion`   | `string`   | Name of the import format.                                       |
| `columnas`             | `string`   | Columns or fields involved in the import process.                |

##### Validation Rules for Parameter Equipment
The following validation rules apply when creating or updating parameter-equipment relationships:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `nullable|string|max:30`
- `producto_delegacion`: `nullable|string|max:10`
- `producto_codigo`: `nullable|string|max:20`
- `formato_importacion`: `nullable|integer`
- `nombre_importacion`: `nullable|string|max:150`
- `columnas`: `nullable|string|max:30`

#### Parameter Consumables

##### Introduction
The Parameter Consumables API provides endpoints to manage consumables associated with parameters. These endpoints allow for creating, retrieving, updating, and deleting consumable records linked to specific parameters.

##### Available Endpoints

###### 1. List Parameter Consumables
- **URL:** `/parametros-consumibles/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of consumables associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "producto_delegacion": "DEL002",
      "producto_codigo": "PRD001",
      "consumo": 10.5
    }
  ]
  ```

###### 2. Retrieve a Specific Consumable
- **URL:** `/parametros-consumibles/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific consumable linked to a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the consumable (product code).
  - `clave2` (optional): Delegation code for the consumable.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "producto_delegacion": "DEL002",
    "producto_codigo": "PRD001",
    "consumo": 10.5
  }
  ```

###### 3. Create a New Consumable
- **URL:** `/parametros-consumibles`
- **Method:** `POST`
- **Description:** Creates a new consumable record linked to a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "producto_delegacion": "DEL002",
    "producto_codigo": "PRD001",
    "consumo": 10.5
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "producto_codigo": "PRD001"
    }
  }
  ```

###### 4. Update a Consumable
- **URL:** `/parametros-consumibles/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing consumable record linked to a parameter.
- **Request Body:**
  ```json
  {
    "consumo": 12.0
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Consumable
- **URL:** `/parametros-consumibles/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a consumable record linked to a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the consumable (product code).
  - `clave2` (optional): Delegation code for the consumable.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameter Consumables
The following fields are supported for the Parameter Consumables API:

| **Field**              | **Type**   | **Description**                                     |
|------------------------|------------|-----------------------------------------------------|
| `parametro_delegacion` | `string`   | Delegation code for the parameter.                 |
| `parametro_codigo`     | `string`   | Identifier for the parameter.                      |
| `producto_delegacion`  | `string`   | Delegation code for the product (consumable).      |
| `producto_codigo`      | `string`   | Identifier for the product (consumable).           |
| `consumo`              | `numeric`  | Quantity of the consumable used.                   |

##### Validation Rules for Parameter Consumables
The following validation rules apply when creating or updating parameter consumable records:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `producto_delegacion`: `nullable|string|max:10`
- `producto_codigo`: `required|string|max:20`
- `consumo`: `nullable|numeric`

#### Parameter Columns

##### Introduction
The Parameter Columns API provides endpoints for managing columns associated with specific parameters. These endpoints allow for creating, retrieving, updating, and deleting parameter columns.

##### Available Endpoints

###### 1. List Parameter Columns
- **URL:** `/parametros-columnas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of all columns associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Parameter code.
  - `delegacion` (optional): Delegation code for the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "TEC001",
      "codigo": 1,
      "titulo": "Title",
      "titulo2": "Title 2",
      "titulo3": "Title 3",
      "formato": "General",
      "mostrar_informes": "T",
      "mostrar_resultados": "F",
      "seleccionables": null,
      "tipo_dato": "T",
      "predeterminado": "Default Value",
      "formula": "A+B",
      "es_editable": "T",
      "es_exactitud": "F",
      "es_precision": "T",
      "es_activada": "T"
    }
  ]
  ```

###### 2. Retrieve a Specific Column
- **URL:** `/parametros-columnas/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific column associated with a parameter.
- **Parameters:**
  - `codigo` (required): Parameter code.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Column code.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "TEC001",
    "codigo": 1,
    "titulo": "Title",
    "titulo2": "Title 2",
    "titulo3": "Title 3",
    "formato": "General",
    "mostrar_informes": "T",
    "mostrar_resultados": "F",
    "seleccionables": null,
    "tipo_dato": "T",
    "predeterminado": "Default Value",
    "formula": "A+B",
    "es_editable": "T",
    "es_exactitud": "F",
    "es_precision": "T",
    "es_activada": "T"
  }
  ```

###### 3. Create a Parameter Column
- **URL:** `/parametros-columnas`
- **Method:** `POST`
- **Description:** Creates a new column for a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "TEC001",
    "titulo": "New Title",
    "formato": "Text",
    "mostrar_informes": "T",
    "tipo_dato": "T",
    "predeterminado": "None",
    "es_editable": "T",
    "es_activada": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 5,
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "TEC001"
    }
  }
  ```

###### 4. Update a Parameter Column
- **URL:** `/parametros-columnas/{codigo}/{delegacion?}/{clave1}`
- **Method:** `PUT`
- **Description:** Updates an existing column for a parameter.
- **Request Body:**
  ```json
  {
    "titulo": "Updated Title",
    "formato": "Number",
    "mostrar_informes": "F",
    "tipo_dato": "N",
    "es_editable": "F",
    "es_activada": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Parameter Column
- **URL:** `/parametros-columnas/{codigo}/{delegacion?}/{clave1}`
- **Method:** `DELETE`
- **Description:** Deletes a specific column for a parameter.
- **Parameters:**
  - `codigo` (required): Parameter code.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Column code.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameter Columns
| **Field**              | **Type**   | **Description**                                                   |
|------------------------|------------|-------------------------------------------------------------------|
| `parametro_delegacion` | `string`   | Delegation code of the parameter.                                 |
| `parametro_codigo`     | `string`   | Code of the parameter.                                            |
| `codigo`               | `integer`  | Column code.                                                      |
| `titulo`               | `string`   | Title of the column.                                              |
| `titulo2`              | `string`   | Secondary title of the column.                                    |
| `titulo3`              | `string`   | Tertiary title of the column.                                     |
| `formato`              | `string`   | Format of the column.                                             |
| `mostrar_informes`     | `string`   | Indicates if the column is shown in reports (`T` or `F`).         |
| `mostrar_resultados`   | `string`   | Indicates if the column is shown in results (`T` or `F`).         |
| `seleccionables`       | `string`   | Selection criteria for the column.                                |
| `tipo_dato`            | `string`   | Data type of the column (`N` for number, `T` for text, etc.).     |
| `predeterminado`       | `string`   | Default value for the column.                                     |
| `formula`              | `string`   | Formula for calculated columns.                                   |
| `es_editable`          | `string`   | Indicates if the column is editable (`T` or `F`).                 |
| `es_exactitud`         | `string`   | Indicates if the column is related to accuracy (`T` or `F`).      |
| `es_precision`         | `string`   | Indicates if the column is related to precision (`T` or `F`).     |
| `es_activada`          | `string`   | Indicates if the column is active (`T` or `F`).                   |

##### Validation Rules for Parameter Columns
The following validation rules apply when creating or updating parameter columns:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `codigo`: `nullable|integer`
- `titulo`: `nullable|string|max:100`
- `titulo2`: `nullable|string|max:100`
- `titulo3`: `nullable|string|max:100`
- `formato`: `nullable|string|max:30`
- `mostrar_informes`: `nullable|string|in:T,F|max:1`
- `mostrar_resultados`: `nullable|string|in:T,F|max:1`
- `seleccionables`: `nullable|string`
- `tipo_dato`: `nullable|string|in:N,T,F,H,C|max:1`
- `predeterminado`: `nullable|string|max:100`
- `formula`: `nullable|string`
- `es_editable`: `nullable|string|in:T,F|max:1`
- `es_exactitud`: `nullable|string|in:T,F|max:1`
- `es_precision`: `nullable|string|in:T,F|max:1`
- `es_activada`: `nullable|string|in:T,F|max:1`

#### Qualified Personnel for Parameters

##### Introduction
The Qualified Personnel for Parameters API allows for managing the relationship between parameters and qualified personnel. These endpoints enable listing, retrieving, creating, updating, and deleting records.

##### Available Endpoints

###### 1. List Qualified Personnel for a Parameter
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of qualified personnel associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "empleado_delegacion": "EMP001",
      "empleado_codigo": "E001",
      "posicion": 1
    }
  ]
  ```

###### 2. Retrieve Qualified Personnel Details
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details about a specific qualified personnel associated with a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the personnel.
  - `clave2` (optional): Delegation code for the personnel.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001",
    "posicion": 1
  }
  ```

###### 3. Assign Qualified Personnel to a Parameter
- **URL:** `/parametros-empleados`
- **Method:** `POST`
- **Description:** Assigns qualified personnel to a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "empleado_delegacion": "EMP001",
    "empleado_codigo": "E001",
    "posicion": 1
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "parametro_delegacion": "DEL001"
    }
  }
  ```

###### 4. Update Personnel Assignment
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `PUT`
- **Description:** Updates an existing personnel assignment for a parameter.
- **Request Body:**
  ```json
  {
    "posicion": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Remove Personnel Assignment
- **URL:** `/parametros-empleados/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Removes a personnel assignment from a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code associated with the parameter.
  - `clave1` (required): Identifier for the personnel.
  - `clave2` (optional): Delegation code for the personnel.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Qualified Personnel for Parameters
The following fields are supported for the Qualified Personnel for Parameters API:

| **Field**               | **Type**   | **Description**                                             |
|-------------------------|------------|-------------------------------------------------------------|
| `parametro_delegacion`  | `string`   | Delegation code associated with the parameter.              |
| `parametro_codigo`      | `string`   | Unique identifier for the parameter.                        |
| `empleado_delegacion`   | `string`   | Delegation code associated with the personnel.              |
| `empleado_codigo`       | `integer`  | Unique identifier for the personnel.                        |
| `posicion`              | `integer`  | Position of the personnel in relation to the parameter.     |

##### Validation Rules for Qualified Personnel for Parameters
The following validation rules apply when creating or updating records:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `empleado_delegacion`: `nullable|string|max:10`
- `empleado_codigo`: `required|integer`
- `posicion`: `nullable|integer|min:1`

#### Parameter Intervals

##### Introduction
The Parameter Intervals API provides endpoints to manage defined intervals associated with parameters. These endpoints allow for creating, retrieving, updating, and deleting interval definitions for parameters.

##### Available Endpoints

###### 1. List Defined Intervals
- **URL:** `/parametros-intervalos/{codigo}/{delegacion?}/{clave1}`
- **Method:** `GET`
- **Description:** Retrieves a list of defined intervals associated with a specific parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Identifier for the column associated with the parameter.
- **Example Response:**
  ```json
  [
    {
      "parametro_delegacion": "DEL001",
      "parametro_codigo": "P001",
      "columna_codigo": 10,
      "rango_delegacion": "DEL002",
      "rango_codigo": 20,
      "valor": "Sample Value",
      "marca_delegacion": "DEL003",
      "marca_codigo": 30
    }
  ]
  ```

###### 2. Retrieve Interval Details
- **URL:** `/parametros-intervalos/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}`
- **Method:** `GET`
- **Description:** Retrieves detailed information about a specific defined interval.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Identifier for the column associated with the parameter.
  - `clave2` (required): Range code.
  - `clave3` (optional): Range delegation code.
- **Example Response:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "columna_codigo": 10,
    "rango_delegacion": "DEL002",
    "rango_codigo": 20,
    "valor": "Sample Value",
    "marca_delegacion": "DEL003",
    "marca_codigo": 30
  }
  ```

###### 3. Create a New Interval
- **URL:** `/parametros-intervalos`
- **Method:** `POST`
- **Description:** Creates a new interval for a parameter.
- **Request Body:**
  ```json
  {
    "parametro_delegacion": "DEL001",
    "parametro_codigo": "P001",
    "columna_codigo": 10,
    "rango_delegacion": "DEL002",
    "rango_codigo": 20,
    "valor": "Sample Value",
    "marca_delegacion": "DEL003",
    "marca_codigo": 30
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "parametro_codigo": "P001",
      "columna_codigo": 10,
      "rango_codigo": 20
    }
  }
  ```

###### 4. Update an Interval
- **URL:** `/parametros-intervalos/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}`
- **Method:** `PUT`
- **Description:** Updates an existing interval for a parameter.
- **Request Body:**
  ```json
  {
    "valor": "Updated Value",
    "marca_delegacion": "DEL004",
    "marca_codigo": 40
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Interval
- **URL:** `/parametros-intervalos/{codigo}/{delegacion?}/{clave1}/{clave2}/{clave3?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific interval for a parameter.
- **Parameters:**
  - `codigo` (required): Identifier for the parameter.
  - `delegacion` (optional): Delegation code for the parameter.
  - `clave1` (required): Identifier for the column associated with the parameter.
  - `clave2` (required): Range code.
  - `clave3` (optional): Range delegation code.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Parameter Intervals
The following fields are supported for parameter intervals:

| **Field**              | **Type**   | **Description**                                        |
|------------------------|------------|--------------------------------------------------------|
| `parametro_delegacion` | `string`   | Delegation code for the parameter.                     |
| `parametro_codigo`     | `string`   | Identifier for the parameter.                          |
| `columna_codigo`       | `integer`  | Identifier for the column associated with the interval.|
| `rango_delegacion`     | `string`   | Delegation code for the range.                         |
| `rango_codigo`         | `integer`  | Range code for the interval.                           |
| `valor`                | `string`   | Value associated with the interval.                    |
| `marca_delegacion`     | `string`   | Delegation code for the brand.                         |
| `marca_codigo`         | `integer`  | Code for the brand.                                    |

##### Validation Rules for Parameter Intervals
The following validation rules apply when creating or updating parameter intervals:

- `parametro_delegacion`: `nullable|string|max:10`
- `parametro_codigo`: `required|string|max:30`
- `columna_codigo`: `required|integer`
- `rango_delegacion`: `nullable|string|max:10`
- `rango_codigo`: `required|integer`
- `valor`: `nullable|string|max:100`
- `marca_delegacion`: `nullable|string|max:10`
- `marca_codigo`: `nullable|integer`

#### Sections

##### Introduction
The Sections API provides endpoints for managing sections within the system, allowing for creating, retrieving, updating, and deleting section records.

##### Available Endpoints

###### 1. List Sections
- **URL:** `/secciones`
- **Method:** `GET`
- **Description:** Retrieves a list of all sections.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Analysis Section",
      "icono": 123,
      "posicion": 1,
      "es_baja": "F",
      "departamento_delegacion": "DEP001",
      "departamento_codigo": 10
    }
  ]
  ```

###### 2. Retrieve a Specific Section
- **URL:** `/secciones/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific section.
- **Parameters:**
  - `codigo` (required): Unique identifier for the section.
  - `delegacion` (optional): Delegation code associated with the section.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Analysis Section",
    "icono": 123,
    "posicion": 1,
    "es_baja": "F",
    "departamento_delegacion": "DEP001",
    "departamento_codigo": 10
  }
  ```

###### 3. Create a New Section
- **URL:** `/secciones`
- **Method:** `POST`
- **Description:** Creates a new section.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 2,
    "descripcion": "Research Section",
    "icono": 456,
    "posicion": 2,
    "es_baja": "F",
    "departamento_delegacion": "DEP002",
    "departamento_codigo": 20
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 2,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Section
- **URL:** `/secciones/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing section.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Research Section",
    "icono": 789,
    "posicion": 3,
    "es_baja": "T",
    "departamento_delegacion": "DEP003",
    "departamento_codigo": 30
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Section
- **URL:** `/secciones/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific section from the system.
- **Parameters:**
  - `codigo` (required): Unique identifier for the section.
  - `delegacion` (optional): Delegation code associated with the section.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Sections
The following fields are supported for the Sections API:

| **Field**                 | **Type**   | **Description**                                                              |
|---------------------------|------------|------------------------------------------------------------------------------|
| `delegacion`              | `string`   | Delegation code associated with the section.                                 |
| `codigo`                  | `integer`  | Unique identifier for the section.                                           |
| `descripcion`             | `string`   | Description or name of the section.                                          |
| `icono`                   | `integer`  | Icon associated with the section.                                            |
| `posicion`                | `integer`  | Position of the section in the hierarchy.                                    |
| `tipo`                    | `string`   | Section type per control chart (`T` microbiological, `F` physiochemical).    |
| `es_baja`                 | `string`   | Indicates if the section is inactive (`T` for true, `F` for false).          |
| `departamento_delegacion` | `string`   | Delegation code for the department associated with the section.              |
| `departamento_codigo`     | `integer`  | Code for the department associated with the section.                         |

##### Validation Rules for Sections
The following validation rules apply when creating or updating sections:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:100`
- `icono`: `nullable|integer`
- `posicion`: `nullable|integer`
- `tipo`: `nullable|string|in:M,F|max:1`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `departamento_delegacion`: `nullable|string|max:10`
- `departamento_codigo`: `nullable|integer`

#### Matrix

##### Introduction
The Matrix API provides endpoints to manage matrices in the system. These endpoints allow creating, retrieving, updating, and deleting matrices.

##### Available Endpoints

###### 1. List Matrices
- **URL:** `/matrices`
- **Method:** `GET`
- **Description:** Retrieves a list of all matrices.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Matrix Description",
      "es_baja": "F"
    }
  ]
  ```

###### 2. Retrieve a Matrix
- **URL:** `/matrices/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific matrix.
- **Parameters:**
  - `codigo` (required): Identifier for the matrix.
  - `delegacion` (optional): Delegation code associated with the matrix.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Matrix Description",
    "es_baja": "F"
  }
  ```

###### 3. Create a Matrix
- **URL:** `/matrices`
- **Method:** `POST`
- **Description:** Creates a new matrix.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Matrix Description",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Matrix
- **URL:** `/matrices/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing matrix.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Matrix Description",
    "es_baja": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Matrix
- **URL:** `/matrices/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a matrix from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the matrix.
  - `delegacion` (optional): Delegation code associated with the matrix.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Matrices
The following fields are supported for the Matrices API:

| **Field**     | **Type**   | **Description**                                 |
|---------------|------------|-------------------------------------------------|
| `delegacion`  | `string`   | Delegation code associated with the matrix.     |
| `codigo`      | `integer`  | Unique identifier for the matrix.               |
| `descripcion` | `string`   | Description of the matrix.                      |
| `es_baja`     | `string`   | Indicates if the matrix is inactive (`T`, `F`). |

##### Validation Rules for Matrices
The following validation rules apply when creating or updating matrices:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:255`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Matrix Operation Types

##### Introduction
The Matrix Operation Types API provides endpoints for managing operation types associated with matrices. These endpoints allow listing, retrieving, creating, and deleting operation type relationships.

##### Available Endpoints

###### 1. List Operation Types for a Matrix
- **URL:** `/matrices-tipos-operacion/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves a list of operation types associated with a specific matrix.
- **Parameters:**
  - `codigo` (required): Identifier for the matrix.
  - `delegacion` (optional): Delegation code associated with the matrix.
- **Example Response:**
  ```json
  [
    {
      "matriz_delegacion": "DEL001",
      "matriz_codigo": 101,
      "tipo_operacion_delegacion": "TIO001",
      "tipo_operacion_codigo": 501
    }
  ]
  ```

###### 2. Retrieve Operation Type Details
- **URL:** `/matrices-tipos-operacion/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `GET`
- **Description:** Retrieves details of a specific operation type associated with a matrix.
- **Parameters:**
  - `codigo` (required): Identifier for the matrix.
  - `delegacion` (optional): Delegation code associated with the matrix.
  - `clave1` (required): Identifier for the operation type.
  - `clave2` (optional): Delegation code for the operation type.
- **Example Response:**
  ```json
  {
    "matriz_delegacion": "DEL001",
    "matriz_codigo": 101,
    "tipo_operacion_delegacion": "TIO001",
    "tipo_operacion_codigo": 501
  }
  ```

###### 3. Create an Operation Type for a Matrix
- **URL:** `/matrices-tipos-operacion`
- **Method:** `POST`
- **Description:** Creates a new relationship between a matrix and an operation type.
- **Request Body:**
  ```json
  {
    "matriz_delegacion": "DEL001",
    "matriz_codigo": 101,
    "tipo_operacion_delegacion": "TIO001",
    "tipo_operacion_codigo": 501
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "matriz_codigo": 101,
      "tipo_operacion_codigo": 501
    }
  }
  ```

###### 4. Delete an Operation Type from a Matrix
- **URL:** `/matrices-tipos-operacion/{codigo}/{delegacion?}/{clave1}/{clave2?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific operation type relationship from a matrix.
- **Parameters:**
  - `codigo` (required): Identifier for the matrix.
  - `delegacion` (optional): Delegation code associated with the matrix.
  - `clave1` (required): Identifier for the operation type.
  - `clave2` (optional): Delegation code for the operation type.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Matrices Operation Types
The following fields are supported for the Matrices Operation Types API:

| **Field**                     | **Type**   | **Description**                                       |
|-------------------------------|------------|-------------------------------------------------------|
| `matriz_delegacion`           | `string`   | Delegation code associated with the matrix.           |
| `matriz_codigo`               | `integer`  | Identifier for the matrix.                            |
| `tipo_operacion_delegacion`   | `string`   | Delegation code associated with the operation type.   |
| `tipo_operacion_codigo`       | `integer`  | Identifier for the operation type.                    |

##### Validation Rules for Matrices Operation Types
The following validation rules apply when creating or updating matrix-operation type relationships:

- `matriz_delegacion`: `nullable|string|max:10`
- `matriz_codigo`: `required|integer`
- `tipo_operacion_delegacion`: `nullable|string|max:10`
- `tipo_operacion_codigo`: `required|integer`

#### Operation Types

##### Introduction
The Operation Types API provides endpoints to manage the different operation types within the system. These endpoints allow for creating, retrieving, updating, and deleting operation types.

##### Available Endpoints

###### 1. List Operation Types
- **URL:** `/tipos-operacion`
- **Method:** `GET`
- **Description:** Retrieves a list of all operation types.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "nombre": "Standard Operation",
      "es_predeterminado": "T",
      "es_gestionable_equipos": "F",
      "es_gestionable_parametros": "T",
      "es_baja": "F"
    }
  ]
  ```

###### 2. Retrieve Operation Type Details
- **URL:** `/tipos-operacion/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific operation type.
- **Parameters:**
  - `codigo` (required): Identifier for the operation type.
  - `delegacion` (optional): Delegation code associated with the operation type.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "nombre": "Standard Operation",
    "es_predeterminado": "T",
    "es_gestionable_equipos": "F",
    "es_gestionable_parametros": "T",
    "es_baja": "F"
  }
  ```

###### 3. Create a New Operation Type
- **URL:** `/tipos-operacion`
- **Method:** `POST`
- **Description:** Creates a new operation type.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "nombre": "Standard Operation",
    "es_predeterminado": "T",
    "es_gestionable_equipos": "F",
    "es_gestionable_parametros": "T",
    "es_baja": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update an Operation Type
- **URL:** `/tipos-operacion/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing operation type.
- **Request Body:**
  ```json
  {
    "nombre": "Updated Operation",
    "es_predeterminado": "F",
    "es_gestionable_equipos": "T",
    "es_gestionable_parametros": "F",
    "es_baja": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Operation Type
- **URL:** `/tipos-operacion/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific operation type.
- **Parameters:**
  - `codigo` (required): Identifier for the operation type.
  - `delegacion` (optional): Delegation code associated with the operation type.
- **Example Response:**
  ```json
  {
    "message": "Registro borrado correctamente"
  }
  ```

##### Available Fields for Operation Types
The following fields are supported for the Operation Types API:

| **Field**                   | **Type**   | **Description**                                             |
|-----------------------------|------------|-------------------------------------------------------------|
| `delegacion`                | `string`   | Delegation code associated with the operation type.         |
| `codigo`                    | `integer`  | Unique identifier for the operation type.                   |
| `nombre`                    | `string`   | Name of the operation type.                                 |
| `es_predeterminado`         | `string`   | Indicates if the operation type is default (`T/F`).         |
| `es_gestionable_equipos`    | `string`   | Indicates if the operation type manages equipment (`T/F`).  |
| `es_gestionable_parametros` | `string`   | Indicates if the operation type manages parameters (`T/F`). |
| `es_baja`                   | `string`   | Indicates if the operation type is inactive (`T/F`).        |

##### Validation Rules for Operation Types
The following validation rules apply when creating or updating operation types:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `nombre`: `nullable|string|max:50`
- `es_predeterminado`: `nullable|string|in:T,F|max:1`
- `es_gestionable_equipos`: `nullable|string|in:T,F|max:1`
- `es_gestionable_parametros`: `nullable|string|in:T,F|max:1`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Normatives 

##### Introduction
The Normatives API provides endpoints to manage normatives in the system. These endpoints allow for creating, retrieving, updating, and deleting normatives.

##### Available Endpoints

###### 1. List Normatives
- **URL:** `/normativas`
- **Method:** `GET`
- **Description:** Retrieves a list of all normatives.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "NORM001",
      "descripcion": "Environmental Standards",
      "abreviatura": "ENV_STD",
      "observaciones": "Mandatory for all tests",
      "es_desglose": "T",
      "fecha_baja": null,
      "es_baja": "F"
    }
  ]
  ```

###### 2. Retrieve Normative Details
- **URL:** `/normativas/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific normative.
- **Parameters:**
  - `codigo` (required): Identifier for the normative.
  - `delegacion` (optional): Delegation code associated with the normative.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "NORM001",
    "descripcion": "Environmental Standards",
    "abreviatura": "ENV_STD",
    "observaciones": "Mandatory for all tests",
    "es_desglose": "T",
    "fecha_baja": null,
    "es_baja": "F"
  }
  ```

###### 3. Create a Normative
- **URL:** `/normativas`
- **Method:** `POST`
- **Description:** Creates a new normative.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "NORM001",
    "descripcion": "Environmental Standards",
    "abreviatura": "ENV_STD",
    "observaciones": "Mandatory for all tests",
    "es_desglose": "T"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "NORM001",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update a Normative
- **URL:** `/normativas/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing normative.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Environmental Standards",
    "abreviatura": "UPD_ENV_STD",
    "observaciones": "Updated mandatory standards",
    "es_desglose": "F"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete a Normative
- **URL:** `/normativas/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a normative from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the normative.
  - `delegacion` (optional): Delegation code associated with the normative.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Normatives
The following fields are supported for the Normatives API:

| **Field**        | **Type**   | **Description**                                   |
|------------------|------------|---------------------------------------------------|
| `delegacion`     | `string`   | Delegation code associated with the normative.    |
| `codigo`         | `string`   | Unique identifier for the normative.              |
| `descripcion`    | `string`   | Description of the normative.                     |
| `abreviatura`    | `string`   | Abbreviation for the normative.                   |
| `observaciones`  | `string`   | Additional observations or notes.                 |
| `es_desglose`    | `string`   | Indicates if the normative has breakdown (`T/F`). |
| `fecha_baja`     | `date`     | Date when the normative was deactivated.          |
| `es_baja`        | `string`   | Indicates if the normative is inactive (`T/F`).   |

#### Validation Rules for Normatives
The following validation rules apply when creating or updating normatives:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:20`
- `descripcion`: `nullable|string`
- `abreviatura`: `nullable|string|max:50`
- `observaciones`: `nullable|string`
- `es_desglose`: `nullable|string|in:T,F|max:1`
- `fecha_baja`: `nullable|date`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Additional Expenses 

##### Introduction
The Additional Expenses API provides endpoints for managing extra costs in the system, including creation, retrieval, updating, and deletion of expense records.

##### Available Endpoints

###### 1. List Expenses
- **URL:** `/gastos`
- **Method:** `GET`
- **Description:** Retrieves a list of all additional expenses.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 101,
      "descripcion": "Transportation Cost",
      "observaciones": "For samples transportation",
      "es_suplido": "F",
      "precio": 50.00,
      "descuento": "10%",
      "fecha_baja": null,
      "es_baja": "F"
    }
  ]
  ```

###### 2. Retrieve an Expense
- **URL:** `/gastos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific expense.
- **Parameters:**
  - `codigo` (required): Identifier for the expense.
  - `delegacion` (optional): Delegation code associated with the expense.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 101,
    "descripcion": "Transportation Cost",
    "observaciones": "For samples transportation",
    "es_suplido": "F",
    "precio": 50.00,
    "descuento": "10%",
    "fecha_baja": null,
    "es_baja": "F"
  }
  ```

###### 3. Create an Expense
- **URL:** `/gastos`
- **Method:** `POST`
- **Description:** Creates a new expense.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 102,
    "descripcion": "Packaging",
    "observaciones": "Expense for packaging materials",
    "es_suplido": "T",
    "precio": 30.00,
    "descuento": "5%"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 102,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update an Expense
- **URL:** `/gastos/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing expense record.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Packaging Expense",
    "observaciones": "Updated expense for packaging materials",
    "precio": 35.00
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Expense
- **URL:** `/gastos/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a specific expense from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the expense.
  - `delegacion` (optional): Delegation code associated with the expense.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Additional Expenses
The following fields are supported for additional expenses:

| **Field**        | **Type**   | **Description**                                       |
|------------------|------------|-------------------------------------------------------|
| `delegacion`     | `string`   | Delegation code associated with the expense.          |
| `codigo`         | `integer`  | Unique identifier for the expense.                    |
| `descripcion`    | `string`   | Description of the expense.                           |
| `observaciones`  | `string`   | Additional observations related to the expense.       |
| `es_suplido`     | `string`   | Indicates if the expense is a supplanted cost (`T/F`).|
| `precio`         | `numeric`  | Price associated with the expense.                    |
| `descuento`      | `string`   | Discount applied to the expense.                      |
| `fecha_baja`     | `date`     | Deactivation date of the expense.                     |
| `es_baja`        | `string`   | Indicates if the expense is deactivated (`T/F`).      |

##### Validation Rules for Additional Expenses
The following validation rules apply when creating or updating additional expenses:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:100`
- `observaciones`: `nullable|string`
- `es_suplido`: `nullable|string|in:T,F|max:1`
- `precio`: `nullable|numeric`
- `descuento`: `nullable|string|max:15`
- `fecha_baja`: `nullable|date`
- `es_baja`: `nullable|string|in:T,F|max:1`

#### Client Equipment

##### Introduction
The Client Equipment API provides endpoints to manage equipment associated with clients within the system. These endpoints allow for creating, retrieving, updating, and deleting client equipment records.

##### Available Endpoints

###### 1. List Equipment
- **URL:** `/equipos`
- **Method:** `GET`
- **Description:** Retrieves a list of all client equipment.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": "EQU123",
      "descripcion": "Generator Unit",
      "marca": "GenCorp",
      "modelo": "GX-200",
      "estado": "N",
      "fecha_servicio": "2023-01-15",
      "cliente_codigo": "CLI001"
    }
  ]
  ```

###### 2. Retrieve Specific Equipment
- **URL:** `/equipos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for specific client equipment.
- **Parameters:**
  - `codigo` (required): Identifier for the equipment.
  - `delegacion` (optional): Delegation code associated with the equipment.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "EQU123",
    "descripcion": "Generator Unit",
    "marca": "GenCorp",
    "modelo": "GX-200",
    "serie": "SER00123",
    "fabricante": "GenCorp",
    "estado": "N",
    "fecha_servicio": "2023-01-15",
    "observaciones": "Operational",
    "cliente_codigo": "CLI001"
  }
  ```

###### 3. Create New Equipment
- **URL:** `/equipos`
- **Method:** `POST`
- **Description:** Creates a new client equipment record.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": "EQU124",
    "descripcion": "Transformer",
    "marca": "TransCo",
    "modelo": "TX-500",
    "estado": "N",
    "cliente_codigo": "CLI001"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": "EQU124",
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update Equipment
- **URL:** `/equipos/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing client equipment record.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Transformer",
    "marca": "UpdatedCo",
    "estado": "L",
    "observaciones": "Under maintenance"
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete Equipment
- **URL:** `/equipos/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes a client equipment record from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the equipment.
  - `delegacion` (optional): Delegation code associated with the equipment.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Client Equipment
The following fields are supported for client equipment:

| **Field**                  | **Type**   | **Description**                                         |
|----------------------------|------------|---------------------------------------------------------|
| `delegacion`               | `string`   | Delegation code associated with the equipment.          |
| `codigo`                   | `string`   | Unique identifier for the equipment.                    |
| `descripcion`              | `string`   | Description or name of the equipment.                   |
| `marca`                    | `string`   | Manufacturer brand of the equipment.                    |
| `modelo`                   | `string`   | Model name or number of the equipment.                  |
| `serie`                    | `string`   | Serial number of the equipment.                         |
| `referencia`               | `string`   | Reference code for the equipment.                       |
| `fabricante`               | `string`   | Manufacturer of the equipment.                          |
| `ano_fabricacion`          | `string`   | Year of manufacture.                                    |
| `ubicacion`                | `string`   | Physical location of the equipment.                     |
| `manual`                   | `string`   | Path or reference to the operation manual.              |
| `especificaciones`         | `string`   | Technical specifications of the equipment.              |
| `condiciones`              | `string`   | Operating conditions required for the equipment.        |
| `tipo_fluido`              | `string`   | Type of fluid used by the equipment (if applicable).    |
| `volumen_fluido`           | `string`   | Fluid volume capacity of the equipment.                 |
| `preservacion`             | `string`   | Preservation methods for the equipment.                 |
| `enfriamiento`             | `string`   | Cooling method of the equipment.                        |
| `reglas_analisis`          | `string`   | Analytical rules associated with the equipment.         |
| `rango_kv`                 | `string`   | Voltage range (kV) for the equipment.                   |
| `rango_mva`                | `string`   | Power range (MVA) for the equipment.                    |
| `estado`                   | `string`   | Operational state (`N`, `U`, `L`, `F`, `B`).            |
| `fecha_servicio`           | `date`     | Date the equipment was put into service.                |
| `fecha_baja`               | `date`     | Deactivation date of the equipment.                     |
| `observaciones`            | `string`   | Additional notes or remarks.                            |
| `es_baja`                  | `string`   | Indicates if the equipment is inactive (`T`, `F`).      |
| `tipo_equipo_delegacion`   | `string`   | Delegation code for the type of equipment.              |
| `tipo_equipo_codigo`       | `integer`  | Code for the type of equipment.                         |
| `cliente_delegacion`       | `string`   | Delegation code of the associated client.               |
| `cliente_codigo`           | `string`   | Code of the associated client.                          |

##### Validation Rules for Client Equipment
The following validation rules apply when creating or updating client equipment:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|string|max:20`
- `descripcion`: `nullable|string|max:100`
- `marca`: `nullable|string|max:50`
- `modelo`: `nullable|string|max:50`
- `serie`: `nullable|string|max:30`
- `referencia`: `nullable|string|max:30`
- `fabricante`: `nullable|string|max:50`
- `ano_fabricacion`: `nullable|string|max:10`
- `ubicacion`: `nullable|string|max:255`
- `manual`: `nullable|string|max:255`
- `especificaciones`: `nullable|string|max:255`
- `condiciones`: `nullable|string|max:255`
- `tipo_fluido`: `nullable|string|max:50`
- `volumen_fluido`: `nullable|string|max:50`
- `preservacion`: `nullable|string|max:50`
- `enfriamiento`: `nullable|string|max:50`
- `reglas_analisis`: `nullable|string|max:50`
- `rango_kv`: `nullable|string|max:20`
- `rango_mva`: `nullable|string|max:20`
- `estado`: `nullable|string|in:N,U,L,F,B|max:1`
- `fecha_servicio`: `nullable|date`
- `fecha_baja`: `nullable|date`
- `observaciones`: `nullable|string`
- `es_baja`: `nullable|string|in:T,F|max:1`
- `tipo_equipo_delegacion`: `nullable|string|max:10`
- `tipo_equipo_codigo`: `nullable|integer`
- `cliente_delegacion`: `nullable|string|max:10`
- `cliente_codigo`: `nullable|string|max:15`

#### Equipment Types

##### Introduction
The Equipment Types API provides endpoints to manage different types of equipment in the system. These endpoints allow for creating, retrieving, updating, and deleting equipment types.

##### Available Endpoints

###### 1. List Equipment Types
- **URL:** `/tipos-equipos`
- **Method:** `GET`
- **Description:** Retrieves a list of all equipment types.
- **Example Response:**
  ```json
  [
    {
      "delegacion": "DEL001",
      "codigo": 1,
      "descripcion": "Laboratory Equipment",
      "tipo_equipo_delegacion": "DEL002",
      "tipo_equipo_codigo": 2
    }
  ]
  ```

###### 2. Retrieve a Specific Equipment Type
- **URL:** `/tipos-equipos/{codigo}/{delegacion?}`
- **Method:** `GET`
- **Description:** Retrieves details for a specific equipment type.
- **Parameters:**
  - `codigo` (required): Identifier for the equipment type.
  - `delegacion` (optional): Delegation code associated with the equipment type.
- **Example Response:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Laboratory Equipment",
    "tipo_equipo_delegacion": "DEL002",
    "tipo_equipo_codigo": 2
  }
  ```

###### 3. Create a New Equipment Type
- **URL:** `/tipos-equipos`
- **Method:** `POST`
- **Description:** Creates a new equipment type.
- **Request Body:**
  ```json
  {
    "delegacion": "DEL001",
    "codigo": 1,
    "descripcion": "Laboratory Equipment",
    "tipo_equipo_delegacion": "DEL002",
    "tipo_equipo_codigo": 2
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro creado correctamente",
    "data": {
      "codigo": 1,
      "delegacion": "DEL001"
    }
  }
  ```

###### 4. Update an Equipment Type
- **URL:** `/tipos-equipos/{codigo}/{delegacion?}`
- **Method:** `PUT`
- **Description:** Updates an existing equipment type.
- **Request Body:**
  ```json
  {
    "descripcion": "Updated Equipment Type",
    "tipo_equipo_delegacion": "DEL003",
    "tipo_equipo_codigo": 3
  }
  ```
- **Example Response:**
  ```json
  {
    "message": "Registro actualizado correctamente"
  }
  ```

###### 5. Delete an Equipment Type
- **URL:** `/tipos-equipos/{codigo}/{delegacion?}`
- **Method:** `DELETE`
- **Description:** Deletes an equipment type from the system.
- **Parameters:**
  - `codigo` (required): Identifier for the equipment type.
  - `delegacion` (optional): Delegation code associated with the equipment type.
- **Example Response:**
  ```json
  {
    "message": "Registro eliminado correctamente"
  }
  ```

##### Available Fields for Equipment Types
The following fields are supported for the Equipment Types API:

| **Field**               | **Type**   | **Description**                                         |
|-------------------------|------------|---------------------------------------------------------|
| `delegacion`            | `string`   | Delegation code associated with the equipment type.     |
| `codigo`                | `integer`  | Unique identifier for the equipment type.               |
| `descripcion`           | `string`   | Description of the equipment type.                      |
| `tipo_equipo_delegacion`| `string`   | Delegation code for the parent equipment type.          |
| `tipo_equipo_codigo`    | `integer`  | Code for the parent equipment type.                     |

##### Validation Rules for Equipment Types
The following validation rules apply when creating or updating equipment types:

- `delegacion`: `nullable|string|max:10`
- `codigo`: `nullable|integer`
- `descripcion`: `nullable|string|max:100`
- `tipo_equipo_delegacion`: `nullable|string|max:10`
- `tipo_equipo_codigo`: `nullable|integer`

## Examples

### Basic Usage

#### **Authentication Example (Curl):**

```bash
curl -X POST https://your-domain.com/api/auth/login -H "Content-Type: application/json" -d '{"username":"user@example.com", "password":"your_password"}'
```

#### **Listing Operations (Curl):**

```bash
curl -X GET https://your-domain.com/api/operations -H "Authorization: Bearer your_token_here"
```

#### **Creating a New Service (Curl):**

```bash
curl -X POST https://your-domain.com/api/servicios \
-H "Authorization: Bearer your_token_here" \
-H "Content-Type: application/json" \
-d '{
    "delegacion": "DEL001",
    "codigo": "S001",
    "nombre": "Water Testing",
    "descripcion": "Service for testing water quality",
    "precio": 100.50
}'
```

#### **Retrieving a Specific Parameter (Curl):**

```bash
curl -X GET https://your-domain.com/api/parametros/P001/DEL001 \
-H "Authorization: Bearer your_token_here"
```

#### **Updating a Client Price for a Parameter (Curl):**

```bash
curl -X PUT https://your-domain.com/api/parametros-precios-clientes/P001/DEL001/C001/DEL002 \
-H "Authorization: Bearer your_token_here" \
-H "Content-Type: application/json" \
-d '{
    "precio": 200.00,
    "descuento": "10%"
}'
```

#### **Deleting an Employee Linked to a Parameter (Curl):**

```bash
curl -X DELETE https://your-domain.com/api/parametros-empleados/P001/DEL001/E001/DEL002 \
-H "Authorization: Bearer your_token_here"
```

#### **Listing Equipment Types (Curl):**

```bash
curl -X GET https://your-domain.com/api/tipos-equipos \
-H "Authorization: Bearer your_token_here"
```

#### **Creating a New Delegation (Curl):**

```bash
curl -X POST https://your-domain.com/api/delegaciones \
-H "Authorization: Bearer your_token_here" \
-H "Content-Type: application/json" \
-d '{
    "codigo": "DEL002",
    "nombre": "Delegation 2",
    "direccion": "123 Street",
    "poblacion": "CityName",
    "provincia": "ProvinceName",
    "pais": "Country",
    "telefono": "+123456789"
}'
```

#### **Example Response for Listing Services:**

```json
[
  {
    "delegacion": "DEL001",
    "codigo": "S001",
    "nombre": "Water Testing",
    "descripcion": "Service for testing water quality",
    "precio": 100.50,
    "es_baja": "F"
  },
  {
    "delegacion": "DEL001",
    "codigo": "S002",
    "nombre": "Air Quality Testing",
    "descripcion": "Service for testing air quality",
    "precio": 200.00,
    "es_baja": "F"
  }
]
```

## Error Handling

Common error responses include:

- `401 Unauthorized`: Invalid or missing token.
- `403 Forbidden`: Insufficient permissions.
- `404 Not Found`: Resource does not exist.
- `500 Internal Server Error`: Unexpected server error.

## Best Practices

- Always use HTTPS for secure communication.
- Rotate tokens regularly and store them securely.
- Follow the principle of least privilege when assigning roles and permissions.

## Changelog

### [1.0.0] - 2025-01-10
- Initial release.
- Included authentication and authorization mechanisms.
- Implemented CRUD operations for multiple entities across the system.
- Comprehensive API documentation provided, including examples and validation rules.
- Designed for extensibility and integration with client systems.

## Contributing

We welcome contributions! Please follow these steps:

1. Fork the repository.
2. Create a feature branch.
3. Submit a pull request with a detailed description of your changes.

## License

This project is licensed under the [MIT License](LICENSE).