# DAVVAG MESH — WEB APPLICATION DEVELOPMENT ARCHITECTURE

## AI CONTEXT DOCUMENT

**Document role:** Permanent application architecture and implementation context
**System:** DAVVAG Mesh Web Platform
**Parent architecture:** DAVVAG Framework Application Development Architecture
**Primary stack:** PHP 8+, DAVVAG Framework, Webdock, Vue.js, JavaScript, SOSSData, MySQL, JSON schemas, JSON workflows
**Status:** Architecture Authority
**Scope:** DAVVAG Mesh applications, components, service components, schemas, workflows, tenant registration, permissions, synchronization, devices, networks, telemetry, dashboards, AI and provisioning

---

# 1. PURPOSE

This document defines how the DAVVAG Mesh Web Platform must be implemented inside the existing DAVVAG Framework.

Every developer and AI coding agent working on DAVVAG Mesh must read:

```text
C:\xampp\htdocs\davvag-core\DAVVAG-Framework-App-Development-AI-Context.md

then

DAVVAG-Mesh-Web-App-AI-Context.md
```

The parent DAVVAG Framework architecture remains authoritative.

This document defines only:

```text
HOW DAVVAG MESH USES THE EXISTING FRAMEWORK
```

It must not replace or redesign the DAVVAG Framework.

---

# 2. PRIMARY ARCHITECTURE RULE

DAVVAG Mesh must follow:

```text
ACTIVE TENANT
     ↓
DAVVAG MESH APPLICATIONS
     ↓
APP DESCRIPTORS
     ↓
WEBDOCK COMPONENTS
     ↓
SERVICE COMPONENTS
     ↓
SOSSData
     ↓
SCHEMA JSON
     ↓
WORKFLOWS
     ↓
SHARED DAVVAG CAPABILITIES
```

Do not build DAVVAG Mesh as:

```text
Laravel application

Yii application

independent REST backend

standalone Vue SPA

separate authentication service

separate AI platform

direct MySQL application
```

DAVVAG Mesh is a family of DAVVAG applications.

---

# 3. PRODUCT BOUNDARY

DAVVAG Mesh is a platform for creating intelligent private networks for:

```text
People
Teams
Sensors
Farms
Fishing Fleets
Boats
Vehicles
Equipment
Assets
Remote Operations
Custom Applications
```

The web platform owns:

```text
Organization Configuration

Mesh Network Management

Device Registration

Endpoint Identity

Sensor Configuration

Telemetry Policies

Event Ingestion

Event Synchronization

Current-State Projections

Rules

Alerts

Dashboards

Provisioning

Firmware Catalog

AI Interpretation

Billing
```

The web platform does not own:

```text
LoRa Routing

BLE Radio Control

Physical Sensor Sampling

Firmware Power Management

Phone Background Execution
```

---

# 4. DAVVAG MESH APPLICATION FAMILY

DAVVAG Mesh must not be built as one giant application.

It must be divided into meaningful DAVVAG capability apps.

Recommended application family:

```text
davvag-mesh

davvag-mesh-networks

davvag-mesh-devices

davvag-mesh-events

davvag-mesh-sync

davvag-mesh-telemetry

davvag-mesh-messaging

davvag-mesh-rules

davvag-mesh-alerts

davvag-mesh-provisioning

davvag-mesh-firmware

davvag-mesh-templates

davvag-mesh-ai
```

The app family must follow these rules:

```text
ONE APP PER MEANINGFUL BUSINESS CAPABILITY

NOT ONE APP PER SCREEN

NOT ONE GIANT APP FOR THE WHOLE PLATFORM
```

---

# 5. APP RESPONSIBILITY MAP

## `davvag-mesh`

Role:

```text
Platform shell

Overview dashboard

Network selection

Cross-app navigation

Common app configuration
```

Does not own all business logic.

---

## `davvag-mesh-networks`

Owns:

```text
Mesh network creation

Network configuration

Network membership

Network status

Network application template assignment
```

---

## `davvag-mesh-devices`

Owns:

```text
Physical device registry

Endpoint registration

Device assignment

Device roles

Device health

Device configuration
```

---

## `davvag-mesh-events`

Owns:

```text
Canonical event storage

Event validation

Event deduplication

Event querying

Event projections
```

---

## `davvag-mesh-sync`

Owns:

```text
Mobile gateway synchronization

Sync plans

Known/missing event checks

Batch event ingestion

Configuration synchronization
```

---

## `davvag-mesh-telemetry`

Owns:

```text
Sensor definitions

Sensor bindings

Telemetry policies

Latest sensor state

Telemetry history
```

---

## `davvag-mesh-messaging`

Owns:

```text
Message history

Delivery status

Cloud message projections

Message search
```

It does not replace direct mesh delivery.

---

## `davvag-mesh-rules`

Owns:

```text
Deterministic rule definitions

Rule evaluation

Rule actions

Threshold logic
```

---

## `davvag-mesh-alerts`

Owns:

```text
Active alerts

Alert acknowledgement

Alert resolution

Alert history

Notification coordination
```

---

## `davvag-mesh-provisioning`

Owns:

```text
Device claiming

Provisioning sessions

One-time activation tokens

Device activation

Configuration delivery
```

---

## `davvag-mesh-firmware`

Owns:

```text
Hardware profiles

Firmware builds

Firmware manifests

Supported devices

Release channels
```

---

## `davvag-mesh-templates`

Owns:

```text
People & Teams template

Smart Farming template

Fishing Fleet template

Custom template configuration
```

---

## `davvag-mesh-ai`

Owns:

```text
AI context preparation

Agent selection

Insight history

AI summaries

AI recommendations
```

It must reuse:

```text
ai-agent-creator
```

---

# 6. DEPENDENCY DIRECTION

Recommended dependency direction:

```text
davvag-mesh
       │
       ├── davvag-mesh-networks
       ├── davvag-mesh-devices
       ├── davvag-mesh-events
       ├── davvag-mesh-alerts
       └── davvag-mesh-templates
```

Core relationships:

```text
davvag-mesh-sync
       ↓
davvag-mesh-events
       ↓
davvag-mesh-devices
       ↓
davvag-mesh-networks
```

Telemetry:

```text
davvag-mesh-telemetry
       ↓
davvag-mesh-events
```

Rules:

```text
davvag-mesh-rules
       ↓
davvag-mesh-events
       ↓
davvag-mesh-alerts
```

AI:

```text
davvag-mesh-ai
       ↓
davvag-mesh-events
       ↓
davvag-mesh-alerts
       ↓
ai-agent-creator
```

Do not create circular dependencies.

---

# 7. REQUIRED TENANT PROCEDURE

Before developing or installing DAVVAG Mesh:

```text
READ configloader.php

READ root config.json

RESOLVE RESOURCE_LOCATION

RESOLVE HOST_NAME

CONFIRM TENANT_RESOURCE_LOCATION

ONLY THEN CREATE OR MODIFY MESH APPS
```

Never assume:

```text
davvag-core/localhost
```

is the active tenant.

All DAVVAG Mesh apps must live under:

```text
{TENANT_RESOURCE_LOCATION}/apps/
```

All Mesh schemas must live under:

```text
{TENANT_RESOURCE_LOCATION}/schemas/
```

All Mesh workflows must live under:

```text
{TENANT_RESOURCE_LOCATION}/davvag-flow/
```

---

# 8. ROOT APP STRUCTURE

Example:

```text
apps/davvag-mesh/
├── app.json
├── app.php
├── assets/
│   ├── appicon.png
│   └── mesh-logo.svg
│
├── components/
│   ├── dashboard/
│   │   ├── component.json
│   │   ├── script.js
│   │   ├── partial.html
│   │   └── dashboard.css
│   │
│   ├── network-selector/
│   │   ├── component.json
│   │   ├── script.js
│   │   ├── partial.html
│   │   └── network-selector.css
│   │
│   └── shared-styles/
│       ├── component.json
│       └── mesh.css
│
└── services/
    └── api/
        ├── component.json
        ├── script.js
        └── service.php
```

---

# 9. ROOT APP DESCRIPTOR

Conceptual example:

```json
{
  "components": {
    "dashboard": {
      "type": "component",
      "location": "components/dashboard"
    },
    "network-selector": {
      "type": "component",
      "location": "components/network-selector"
    },
    "shared-styles": {
      "type": "component",
      "location": "components/shared-styles"
    },
    "api": {
      "type": "service",
      "location": "services/api"
    }
  },
  "description": {
    "title": "DAVVAG Mesh",
    "author": "DAVVAG",
    "version": "0.1.0",
    "icon": "assets/appicon.png"
  },
  "tags": [
    "showindock"
  ],
  "configuration": {
    "webdock": {
      "startupComponent": "dashboard",
      "onLoad": [
        "api",
        "shared-styles"
      ],
      "routes": {
        "partials": {
          "/": "dashboard",
          "/networks": "network-selector"
        }
      }
    }
  },
  "dependencies": {
    "apps": [
      "davvag-mesh-networks",
      "davvag-mesh-devices",
      "davvag-mesh-alerts"
    ],
    "schemas": [],
    "workflows": [],
    "plugins": [],
    "php-extensions": []
  }
}
```

Every application requires a complete dependency contract.

Do not use:

```json
"apps": [""]
```

or other blank placeholders.

---

# 10. COMPONENT ARCHITECTURE

Every Mesh frontend feature is implemented using DAVVAG Webdock components.

Example:

```text
components/network-list/
├── component.json
├── script.js
├── partial.html
└── network-list.css
```

Component responsibilities:

```text
Render UI

Maintain temporary view state

Collect user input

Call service components

Open reusable components

Navigate

Show success and error states
```

Components must not:

```text
Contain authoritative business rules

Access MySQL

Execute raw database queries

Make tenant security decisions

Call AI providers directly

Store secrets
```

---

# 11. WEBDOCK COMPONENT PATTERN

Example:

```javascript
WEBDOCK.component().register(function (exports) {
    var api;

    var state = {
        loading: false,
        networks: [],
        error: null
    };

    exports.vue = {
        data: state,

        methods: {
            refresh: loadNetworks,
            openNetwork: openNetwork
        }
    };

    exports.onReady = function () {
        api = exports.getComponent("api");

        loadNetworks();
    };

    function loadNetworks() {
        state.loading = true;
        state.error = null;

        api.services.ListNetworks()
            .then(function (response) {
                if (response.success) {
                    state.networks = response.result || [];
                } else {
                    state.error = response.message || "Unable to load networks.";
                }
            })
            .catch(function () {
                state.error = "Unable to load networks.";
            })
            .finally(function () {
                state.loading = false;
            });
    }

    function openNetwork(networkId) {
        var routes = exports.getShellComponent("soss-routes");

        routes.appNavigate(
            "../network?networkId=" +
            encodeURIComponent(networkId)
        );
    }
});
```

Use:

```text
exports.getComponent()

exports.getAppComponent()

exports.getShellComponent()

exports.Complete()
```

Do not create independent frontend runtimes.

---

# 12. SERVICE COMPONENT ARCHITECTURE

Business logic belongs behind DAVVAG service components.

Example:

```text
services/network-api/
├── component.json
├── script.js
└── service.php
```

Descriptor:

```json
{
  "name": "network-api",
  "serviceHandler": {
    "file": "service.php",
    "class": "davvag_mesh_networks\\NetworkApiService",
    "methods": {
      "CreateNetwork": {
        "method": "POST"
      },
      "GetNetwork": {
        "method": "GET"
      },
      "ListNetworks": {
        "method": "GET"
      },
      "UpdateNetwork": {
        "method": "POST"
      }
    }
  }
}
```

PHP handlers:

```text
POST CreateNetwork
        ↓
postCreateNetwork($req, $res)

GET ListNetworks
        ↓
getListNetworks($req, $res)
```

Do not invent alternate service-handler naming.

---

# 13. SERVICE RESPONSIBILITY

A DAVVAG Mesh service handler must:

```text
READ REQUEST

VALIDATE INPUT

AUTHENTICATE

AUTHORIZE

LOAD TRUSTED RECORDS

EXECUTE DETERMINISTIC BUSINESS RULES

CALL SOSSData

CALL SHARED APP SERVICES

EXECUTE WORKFLOW WHEN NEEDED

CALL AI ONLY AFTER VALIDATION

RETURN STABLE RESULT
```

For simple operations:

```text
Service Component
      ↓
SOSSData
```

For complex operations:

```text
Service Component
      ↓
Internal PHP Helper Classes
      ↓
SOSSData / Workflows / Shared Services
```

Internal classes are allowed when complexity requires them.

They remain behind the DAVVAG service component.

Do not expose a parallel MVC architecture publicly.

---

# 14. INTERNAL PHP CLASS RULE

Complex capabilities such as:

```text
Event Validation

Event Deduplication

Sync Planning

Projection Updates

Telemetry Evaluation
```

may use internal PHP classes.

Example:

```text
services/event-api/
├── component.json
├── script.js
├── service.php
└── lib/
    ├── EventValidator.php
    ├── EventReference.php
    ├── EventIngestion.php
    └── ProjectionUpdater.php
```

Public entry:

```text
DAVVAG SERVICE COMPONENT
```

Internal implementation:

```text
PHP DOMAIN HELPERS
```

This keeps the framework contract intact.

---

# 15. SOSSData IS THE PERSISTENCE FACADE

All normal DAVVAG Mesh persistence uses:

```php
SOSSData::Insert(...);

SOSSData::Update(...);

SOSSData::Delete(...);

SOSSData::Query(...);

SOSSData::ExecuteRaw(...);
```

Do not use:

```text
new mysqli(...)

PDO directly

hard-coded database names

physical table names
```

for ordinary app logic.

The architecture is:

```text
MESH SERVICE
     ↓
SOSSData
     ↓
ACTIVE TENANT CONNECTOR
     ↓
DATASTORE ADAPTER
     ↓
SCHEMA-AWARE STORAGE
```

---

# 16. MESH SCHEMA NAMING

Recommended namespace prefix:

```text
davvag_mesh_
```

Core namespaces:

```text
davvag_mesh_organizations

davvag_mesh_networks

davvag_mesh_network_members

davvag_mesh_devices

davvag_mesh_endpoints

davvag_mesh_sensors

davvag_mesh_sensor_bindings

davvag_mesh_telemetry_policies

davvag_mesh_events

davvag_mesh_event_gateways

davvag_mesh_positions_latest

davvag_mesh_sensor_values_latest

davvag_mesh_messages

davvag_mesh_message_receipts

davvag_mesh_alerts

davvag_mesh_rules

davvag_mesh_hardware_profiles

davvag_mesh_firmware_builds

davvag_mesh_provisioning_sessions

davvag_mesh_templates
```

Each namespace requires:

```text
schemas/{namespace}.json
```

before app code depends on it.

---

# 17. DO NOT CREATE A PARALLEL ORGANIZATION TABLE BLINDLY

Before creating:

```text
davvag_mesh_organizations
```

inspect existing DAVVAG tenant, profile and organization capabilities.

Preferred rule:

```text
REUSE EXISTING ORGANIZATION / PROFILE MODEL
```

when it correctly represents the customer.

Create Mesh-specific organization records only for information that cannot belong in existing platform identity structures.

Do not create a second:

```text
user system

profile system

authentication system
```

---

# 18. MESH NETWORK SCHEMA

Conceptual fields:

```text
id

organization_id

name

code

description

country_code

region_code

status

template_code

configuration_json

created_by
```

The framework automatically manages normal system metadata.

Do not manually recreate:

```text
syscreated

sysupdated

syscreatedby

syslastupdatedby

sysversionid
```

unless intentionally overriding framework behavior.

---

# 19. DEVICE SCHEMA

Conceptual fields:

```text
id

network_id

hardware_profile_id

name

serial_number

manufacturer

model

device_role

firmware_version

firmware_channel

provisioning_status

last_seen_at

status
```

---

# 20. ENDPOINT SCHEMA

Conceptual fields:

```text
id

network_id

device_id

profile_id

endpoint_number

endpoint_type

status

auth_key_version
```

Endpoint types:

```text
FIRMWARE

MOBILE

SERVER_ADAPTER

FUTURE_GATEWAY
```

---

# 21. EVENT ARCHITECTURE

DAVVAG Mesh events are immutable business records.

Core event reference:

```text
network_id

origin_endpoint_id

session_id

sequence
```

Together they must be unique.

Conceptual event schema:

```text
id

network_id

origin_endpoint_id

session_id

sequence

schema_version

event_type

priority

created_at_device

received_at_cloud

time_quality

payload_json

verification_status
```

Do not update an existing event to describe a later state.

Example:

```text
SOS_CREATED

then

SOS_ACKNOWLEDGED

then

SOS_RESOLVED
```

---

# 22. EVENT GATEWAY OBSERVATION

The uploader is not necessarily the origin.

Example:

```text
Origin:
Device A

Uploader:
Phone B
```

Store gateway observations separately.

Conceptual schema:

```text
davvag_mesh_event_gateways

id

event_id

gateway_endpoint_id

received_at

upload_session
```

Never replace:

```text
origin_endpoint_id
```

with:

```text
gateway_endpoint_id
```

---

# 23. EVENT INGESTION SERVICE

Application:

```text
davvag-mesh-events
```

Service:

```text
event-api
```

Methods:

```text
POST IngestEvents

GET GetEvent

GET ListEvents

GET GetEventState
```

Internal flow:

```text
RECEIVE BATCH
     ↓
VALIDATE REQUEST
     ↓
AUTHORIZE GATEWAY
     ↓
RESOLVE NETWORK ACCESS
     ↓
VALIDATE EVENT FORMAT
     ↓
VERIFY EVENT IDENTITY
     ↓
CHECK DUPLICATE
     ↓
INSERT NEW EVENT
     ↓
REGISTER GATEWAY OBSERVATION
     ↓
UPDATE PROJECTIONS
     ↓
EXECUTE RULES
     ↓
CREATE ALERTS
```

---

# 24. IDEMPOTENCY RULE

The same event can arrive through:

```text
Phone A

Phone B

Phone C
```

Expected result:

```text
ONE EVENT RECORD
```

The event uniqueness contract must be enforced by:

```text
network_id
+
origin_endpoint_id
+
session_id
+
sequence
```

The same event may have multiple gateway observations.

---

# 25. SYNC APPLICATION

Application:

```text
davvag-mesh-sync
```

Components:

```text
sync-monitor

gateway-monitor

sync-health
```

Service:

```text
sync-api
```

Methods:

```text
POST PlanSync

POST UploadEvents

POST CompleteSync

GET GetBootstrap

GET GetChanges
```

---

# 26. SYNC PLAN

Mobile sends event references:

```json
{
  "networkId": 100,
  "events": [
    {
      "originEndpointId": 10,
      "sessionId": 5501,
      "sequence": 201
    },
    {
      "originEndpointId": 10,
      "sessionId": 5501,
      "sequence": 202
    }
  ]
}
```

DAVVAG returns:

```json
{
  "known": [
    {
      "originEndpointId": 10,
      "sessionId": 5501,
      "sequence": 201
    }
  ],
  "missing": [
    {
      "originEndpointId": 10,
      "sessionId": 5501,
      "sequence": 202
    }
  ]
}
```

The mobile app uploads only missing events.

---

# 27. SYNC SERVICE IMPLEMENTATION

Recommended:

```text
services/sync-api/
├── component.json
├── script.js
├── service.php
└── lib/
    ├── SyncRequestValidator.php
    ├── SyncPlanner.php
    └── SyncBatchProcessor.php
```

`service.php` remains the DAVVAG public service contract.

---

# 28. PROJECTIONS

Raw events answer:

```text
WHAT HAPPENED?
```

Projections answer:

```text
WHAT IS THE CURRENT STATE?
```

Projection namespaces:

```text
davvag_mesh_positions_latest

davvag_mesh_sensor_values_latest

davvag_mesh_device_state_latest

davvag_mesh_network_state_latest
```

Dashboard components query projections.

They should not replay all historical events on every page load.

---

# 29. RAW QUERY USAGE

Use:

```text
SOSSData::ExecuteRaw()
```

only for:

```text
Map summaries

Aggregates

Dashboard metrics

Joined reports

Time-series summaries

Grouped sensor analysis
```

Raw query definitions belong in schema-controlled definitions.

Rules:

```text
NO SELECT *

NO RAW SQL FROM FRONTEND

CAST NUMERIC INPUT

VALIDATE DATE RANGE

WHITELIST SORT FIELDS

LIMIT RESULTS
```

---

# 30. TELEMETRY APPLICATION

Application:

```text
davvag-mesh-telemetry
```

Components:

```text
sensor-list

sensor-detail

telemetry-chart

telemetry-policy-editor

sensor-health
```

Service:

```text
telemetry-api
```

Methods:

```text
CreateSensor

ListSensors

GetSensor

BindSensor

CreatePolicy

UpdatePolicy

GetLatestValues

GetSensorHistory
```

---

# 31. UNIVERSAL SENSOR MODEL

Do not create:

```text
soil_moisture_table

water_level_table

fuel_table

temperature_table
```

for each sensor type.

Use:

```text
SENSOR DEFINITION

SENSOR BINDING

TELEMETRY EVENT

LATEST VALUE PROJECTION
```

Sensor definition fields:

```text
name

sensor_type

value_type

unit

precision
```

---

# 32. TELEMETRY POLICY

Conceptual fields:

```text
sensor_id

sample_interval

minimum_publish_interval

maximum_heartbeat_interval

absolute_change_threshold

relative_change_threshold

critical_low

critical_high

recovery_low

recovery_high

aggregation_method

delivery_policy
```

The server stores policy.

Firmware or mobile executes policy.

---

# 33. NETWORK TEMPLATE ARCHITECTURE

Application:

```text
davvag-mesh-templates
```

Initial templates:

```text
People & Teams

Smart Farming

Fishing Fleet
```

Templates install configuration.

They do not create independent platform forks.

Template installation can create:

```text
Device Roles

Sensor Definitions

Telemetry Policies

Rules

Dashboard Configuration

AI Agent Bindings

Mobile Feature Configuration
```

---

# 34. TEMPLATE INSTALLATION WORKFLOW

Use DAVVAG Flow for multi-step template installation.

Example workflow:

```text
davvag-flow/
└── davvag-mesh/
    └── install-template.json
```

Conceptual flow:

```text
VALIDATE TEMPLATE
     ↓
CREATE NETWORK CONFIGURATION
     ↓
CREATE DEVICE ROLES
     ↓
CREATE SENSOR DEFINITIONS
     ↓
CREATE TELEMETRY POLICIES
     ↓
CREATE RULES
     ↓
CREATE DASHBOARD CONFIGURATION
     ↓
CREATE AI BINDINGS
     ↓
RETURN INSTALLATION RESULT
```

This is a correct workflow use because it coordinates multiple reusable business operations.

Do not use workflows for simple single-record saves.

---

# 35. RULE ENGINE APPLICATION

Application:

```text
davvag-mesh-rules
```

Rules remain deterministic.

Example:

```text
WHEN

soil moisture < 15

FOR

10 minutes

THEN

create alert
```

Components:

```text
rule-list

rule-editor

rule-history
```

Service methods:

```text
CreateRule

UpdateRule

EnableRule

DisableRule

EvaluateEvent

TestRule
```

---

# 36. ALERT APPLICATION

Application:

```text
davvag-mesh-alerts
```

Alert event flow:

```text
EVENT
  ↓
RULE
  ↓
ALERT CREATED
  ↓
NOTIFICATION
  ↓
ACKNOWLEDGEMENT
  ↓
RESOLUTION
```

Components:

```text
active-alerts

alert-detail

alert-history
```

---

# 37. MESSAGING APPLICATION

Application:

```text
davvag-mesh-messaging
```

The web app stores:

```text
Message history

Delivery projections

Search

Cloud synchronization state
```

The web app does not become required for direct message delivery.

Direct message path remains:

```text
PHONE
  ↓
MESH
  ↓
PHONE
```

Cloud synchronization is secondary.

---

# 38. DEVICE PROVISIONING APPLICATION

Application:

```text
davvag-mesh-provisioning
```

Components:

```text
device-claim

provisioning-wizard

provisioning-session

activation-result
```

Service methods:

```text
CreateProvisioningSession

ClaimDevice

GetProvisioningManifest

CompleteProvisioning

CancelProvisioning
```

---

# 39. PROVISIONING WORKFLOW

Use DAVVAG Flow:

```text
VALIDATE USER

VALIDATE NETWORK

VALIDATE HARDWARE PROFILE

CREATE PROVISIONING SESSION

CREATE ONE-TIME CLAIM TOKEN

REGISTER DEVICE

REGISTER ENDPOINT

GENERATE DEVICE CONFIG

RETURN PROVISIONING PACKAGE
```

Do not store device secrets inside workflow JSON.

---

# 40. FIRMWARE CATALOG APPLICATION

Application:

```text
davvag-mesh-firmware
```

Schemas:

```text
davvag_mesh_hardware_profiles

davvag_mesh_firmware_builds

davvag_mesh_firmware_manifests
```

Hardware profile fields:

```text
manufacturer

model

chipset

radio_chip

supported_frequency_bands

gps_available

battery_available

display_available

firmware_target
```

---

# 41. AI APPLICATION

Application:

```text
davvag-mesh-ai
```

Dependency:

```json
"dependencies": {
  "apps": [
    "ai-agent-creator",
    "davvag-mesh-events",
    "davvag-mesh-alerts"
  ]
}
```

Do not:

```text
Call OpenAI directly

Call Anthropic directly

Store provider API keys

Create separate AI session storage
```

Use:

```text
ai-agent-creator
```

shared interaction service.

---

# 42. AI EXECUTION ORDER

Correct:

```text
VALIDATE INPUT

AUTHORIZE REQUEST

LOAD TRUSTED MESH DATA

EXECUTE DETERMINISTIC RULES

BUILD AI CONTEXT

CALL SAVED DAVVAG AGENT

STORE INSIGHT
```

Incorrect:

```text
SEND RAW USER REQUEST TO AI

LET AI QUERY EVERYTHING

LET AI MAKE SECURITY DECISIONS
```

---

# 43. AI CONTEXT BUILDER

Create a service method such as:

```text
BuildNetworkAiContext
```

Output:

```text
Network Summary

Relevant Devices

Latest Positions

Latest Sensor Values

Active Alerts

Recent Significant Events

Historical Comparison
```

Then call:

```text
POST /components/ai-agent-creator/creator-api/service/InteractWithAgent
```

Pass:

```text
agentCode

message

appCode

appName

profile

conversationKey

context

payload
```

---

# 44. PROFILE AND MEMBER IDENTITY

Use existing DAVVAG profile architecture for people.

Example:

```text
NETWORK MEMBER
      ↓
profile_id
```

Do not create:

```text
davvag_mesh_people
```

unless there is a documented reason that existing profiles cannot represent the person.

Mesh-specific membership belongs in:

```text
davvag_mesh_network_members
```

It references the profile.

---

# 45. AUTHENTICATION

Use existing:

```text
Auth::Autendicate()
```

and framework access mechanisms.

Do not create:

```text
MeshLoginController

JWT authentication system

second user account table
```

unless the whole DAVVAG framework architecture intentionally changes.

Sensitive services must still perform explicit authorization.

Do not trust permissive legacy behavior as security design.

---

# 46. GROUP VISIBILITY

Apps must be registered intentionally.

Possible:

```text
sysadmin.json

web_user.json
```

Do not add administrative Mesh apps to:

```text
anonymous.json
```

unless there is a specific public workflow.

Recommended:

```text
davvag-mesh
    web_user
    sysadmin

davvag-mesh-networks
    web_user
    sysadmin

davvag-mesh-devices
    web_user
    sysadmin

davvag-mesh-provisioning
    sysadmin
    authorized operators

davvag-mesh-firmware
    sysadmin
```

Exact visibility depends on platform roles.

---

# 47. CROSS-APP REUSE

DAVVAG Mesh should reuse:

```text
Profiles

File Uploader

Image Cropper

CMS

Payments

Products

Orders

AI Agents

DavvagFlow

Existing notification capabilities

Existing task and scheduling capabilities
```

Examples:

```text
Device image upload
        ↓
davvag-file-uploader
```

```text
Network member selector
        ↓
existing profile lookup
```

```text
Subscription billing
        ↓
existing payment applications
```

---

# 48. MESH DASHBOARD ARCHITECTURE

The dashboard component should combine results from multiple service components.

Example:

```text
davvag-mesh dashboard
        │
        ├── network service
        ├── device service
        ├── event projection service
        ├── alert service
        └── telemetry service
```

Do not duplicate all backend logic in the root dashboard app.

The dashboard is a consumer of capabilities.

---

# 49. DASHBOARD COMPONENT STRUCTURE

```text
components/dashboard/
├── component.json
├── script.js
├── partial.html
└── dashboard.css
```

Dashboard state:

```text
selectedNetwork

networkSummary

deviceSummary

activeAlerts

latestPositions

sensorSummary

syncHealth
```

Do not load unlimited historical events into the main dashboard.

---

# 50. ROUTING

Recommended routes:

```text
/

../networks

../network?networkId=...

../devices?networkId=...

../events?networkId=...

../telemetry?networkId=...

../alerts?networkId=...

../settings?networkId=...
```

Use:

```javascript
var routes = exports.getShellComponent("soss-routes");
```

Test routing inside:

```text
normal dock

admin dock
```

when both are supported.

---

# 51. ERROR CONTRACT

Recommended internal service result:

```json
{
  "success": false,
  "code": "NETWORK_ACCESS_DENIED",
  "message": "You do not have access to this network."
}
```

Keep service response shapes stable.

Do not change a public service response without checking all:

```text
frontend callers

workflow callers

cross-app callers
```

---

# 52. CONFIGURATION

Configuration can live in:

```text
tenant config

protected global config

server configuration
```

Never store in frontend:

```text
Device private keys

AI API keys

Provisioning master secrets

Firmware signing secrets

Database credentials
```

---

# 53. WORKFLOW DIRECTORY

Recommended:

```text
davvag-flow/
└── davvag-mesh/
    ├── install-template.json
    ├── provision-device.json
    ├── process-critical-alert.json
    └── generate-network-report.json
```

Use workflows only for genuine multi-step orchestration.

---

# 54. REQUIRED APP CREATION ORDER

For each DAVVAG Mesh app:

```text
1. Resolve active tenant

2. Search existing reusable capabilities

3. Define capability boundary

4. Define app code

5. Define schemas

6. Define service contracts

7. Define workflow needs

8. Define dependencies

9. Create app.json

10. Create app.php

11. Create UI components

12. Create service components

13. Create schema JSON files

14. Create workflows when required

15. Register in tenant.json

16. Register intended group visibility

17. Bump versions

18. Validate JSON

19. Validate PHP syntax

20. Test descriptors

21. Test component resources

22. Test services

23. Test permissions

24. Test supported docks
```

---

# 55. RECOMMENDED V1 BUILD ORDER

Build the Mesh platform in this order.

## Phase 1

```text
davvag-mesh-networks
```

Build:

```text
Network creation

Network listing

Network detail

Network configuration
```

---

## Phase 2

```text
davvag-mesh-devices
```

Build:

```text
Device registry

Endpoint registry

Device assignment

Device state
```

---

## Phase 3

```text
davvag-mesh-events
```

Build:

```text
Canonical event model

Event ingestion

Deduplication

Event history
```

---

## Phase 4

```text
davvag-mesh-sync
```

Build:

```text
Sync plan

Missing event detection

Batch upload

Cloud confirmation
```

---

## Phase 5

```text
davvag-mesh
```

Build:

```text
Platform dashboard

Network selector

Live status
```

---

## Phase 6

```text
davvag-mesh-telemetry
```

Build:

```text
Sensors

Bindings

Policies

Latest values
```

---

## Phase 7

```text
davvag-mesh-alerts
davvag-mesh-rules
```

---

## Phase 8

```text
davvag-mesh-provisioning
davvag-mesh-firmware
```

---

## Phase 9

```text
davvag-mesh-templates
```

Create:

```text
People & Teams

Smart Farming

Fishing Fleet
```

---

## Phase 10

```text
davvag-mesh-ai
```

AI comes after trusted event, projection and alert data exists.

---

# 56. INITIAL SCHEMA ORDER

Create schemas in this order:

```text
1. davvag_mesh_networks

2. davvag_mesh_network_members

3. davvag_mesh_hardware_profiles

4. davvag_mesh_devices

5. davvag_mesh_endpoints

6. davvag_mesh_events

7. davvag_mesh_event_gateways

8. davvag_mesh_positions_latest

9. davvag_mesh_sensors

10. davvag_mesh_sensor_bindings

11. davvag_mesh_telemetry_policies

12. davvag_mesh_sensor_values_latest

13. davvag_mesh_alerts

14. davvag_mesh_rules

15. davvag_mesh_provisioning_sessions

16. davvag_mesh_firmware_builds
```

---

# 57. V1 APP DIRECTORY TARGET

```text
{TENANT_RESOURCE_LOCATION}/

├── apps/
│   ├── davvag-mesh/
│   ├── davvag-mesh-networks/
│   ├── davvag-mesh-devices/
│   ├── davvag-mesh-events/
│   ├── davvag-mesh-sync/
│   ├── davvag-mesh-telemetry/
│   ├── davvag-mesh-alerts/
│   ├── davvag-mesh-rules/
│   ├── davvag-mesh-provisioning/
│   ├── davvag-mesh-firmware/
│   ├── davvag-mesh-templates/
│   └── davvag-mesh-ai/
│
├── schemas/
│   ├── davvag_mesh_networks.json
│   ├── davvag_mesh_devices.json
│   ├── davvag_mesh_endpoints.json
│   ├── davvag_mesh_events.json
│   └── ...
│
├── davvag-flow/
│   └── davvag-mesh/
│       ├── install-template.json
│       ├── provision-device.json
│       └── process-critical-alert.json
│
├── tenant.json
├── web_user.json
└── sysadmin.json
```

---

# 58. TEST REQUIREMENTS

Every app must test:

```text
App Descriptor

Component Descriptors

Scripts

Views

Service Routes

Schema Reads

Schema Writes

Validation

Unauthorized Access

Group Visibility

Cross-App Dependencies

Workflow Failures

Supported Docks
```

For example:

```text
GET /components/object/appdescriptor/davvag-mesh-networks
```

```text
GET /components/davvag-mesh-networks/network-list/object?object=desc
```

```text
POST /components/davvag-mesh-networks/network-api/service/CreateNetwork
```

---

# 59. CRITICAL EVENT TEST

Test:

```text
Event X created by Node A

Phone B uploads Event X

Phone C uploads Event X
```

Expected:

```text
ONE event stored

TWO gateway observations allowed

ONE projection update

ONE rule execution

NO duplicate alert
```

---

# 60. CRITICAL TENANT TEST

Test:

```text
Organization A user requests Organization B network
```

Expected:

```text
DENIED
```

Do not rely only on frontend network filtering.

Backend service authorization is authoritative.

---

# 61. CRITICAL AI TEST

Test:

```text
AI agent unavailable
```

Expected:

```text
Network operations continue

Events continue syncing

Rules continue executing

Alerts continue working
```

AI is optional intelligence.

It is not core infrastructure.

---

# 62. NON-NEGOTIABLE MESH WEB RULES

```text
1. DAVVAG Mesh must follow the DAVVAG Framework architecture.

2. Do not introduce a separate MVC framework.

3. Resolve the active tenant before editing.

4. Mesh capabilities are DAVVAG apps.

5. Apps use app.json as their application contract.

6. UI uses Webdock components.

7. Business logic uses service components.

8. Complex internal PHP classes remain behind service components.

9. SOSSData is the normal persistence facade.

10. Every persisted namespace requires schema JSON.

11. Workflows are used only for meaningful multi-step orchestration.

12. Existing auth and profile systems must be reused.

13. Existing ai-agent-creator must be reused.

14. Every app declares complete dependencies.

15. Events are immutable.

16. Event origin and gateway uploader are different identities.

17. Sync must be idempotent.

18. Current state is stored in projections.

19. AI does not replace deterministic rules.

20. Do not build cloud dependency into field operations.

21. Do not put authoritative business logic in Vue components.

22. Do not access MySQL directly from ordinary app services.

23. Do not create one app per screen.

24. Do not build one giant Mesh monolith.

25. Preserve existing framework source spellings.

26. Register apps in tenant and group files.

27. Bump versions after resource changes.

28. Validate JSON and PHP before browser testing.

29. Do not claim tests passed unless they were executed.

30. Build reusable DAVVAG capabilities, not isolated feature code.
```

---

# 63. AI CODING AGENT REQUIRED READING

Before working on DAVVAG Mesh Web:

```text
1. READ DAVVAG-Framework-App-Development-AI-Context.md

2. READ DAVVAG-Mesh-Web-App-AI-Context.md

3. RESOLVE ACTIVE TENANT

4. READ TARGET MESH APP

5. READ RELATED SCHEMAS

6. READ RELATED WORKFLOWS

7. SEARCH EXISTING DAVVAG CAPABILITIES

8. ONLY THEN DESIGN CHANGES
```

---

# 64. AI CODING AGENT EXECUTION PROTOCOL

## Understand

```text
Resolve active tenant.

Identify target Mesh app.

Identify related schemas.

Identify related workflows.

Identify cross-app dependencies.

Search existing reusable DAVVAG capabilities.
```

## Design

Define:

```text
App capability

Components

Service methods

Schema namespaces

Workflow requirements

App dependencies

Plugin dependencies

Group access

Authorization checks

Test routes
```

## Implement

```text
Create or modify app files.

Create or modify schemas.

Create workflows when required.

Declare dependencies.

Register apps.

Register intended visibility.

Preserve existing tenant content.

Bump versions.
```

## Validate

```text
Parse JSON.

Check PHP syntax.

Check component paths.

Check service class namespace.

Check handler names.

Check schema references.

Check dependencies.

Check routes.
```

## Test

```text
Test descriptors.

Test components.

Test service routes.

Test data writes.

Test data reads.

Test permissions.

Test workflows.

Test cross-app calls.

Test supported docks.
```

## Report

Report:

```text
Files created

Files modified

Apps added

Schemas added

Workflows added

Dependencies added

Routes added

Services added

Security decisions

Tests executed

Known limitations
```

---

# 65. FINAL DEVELOPMENT PRINCIPLE

The DAVVAG Mesh Web Platform must be understood as:

```text
TENANT
   ↓
MESH APPLICATION FAMILY
   ↓
APP DESCRIPTORS
   ↓
WEBDOCK COMPONENTS
   ↓
SERVICE COMPONENTS
   ↓
SOSSData
   ↓
SCHEMA CONTRACTS
   ↓
WORKFLOWS + SHARED CAPABILITIES
```

The correct question when adding a DAVVAG Mesh feature is:

> Which DAVVAG application capability owns this feature, and how should it use the existing DAVVAG contracts?

Not:

> What new architecture should be invented for this feature?

This document is the architecture authority for DAVVAG Mesh Web application development.
