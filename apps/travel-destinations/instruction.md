# Build a DAVVAG Application: `travel-destinations`

Create a new tenant-aware DAVVAG application named:

```text
travel-destinations
```

The application will be a public travel destination discovery platform for camping places, hiking locations, accommodation, villages and other outdoor attractions.

Administrators must be able to create and manage destinations. Authenticated travelers must be able to submit destinations for approval, upload photos, comment, review places and report incorrect information. Anonymous public users must be able to search, browse, view maps and obtain destination coordinates and directions.

Follow the existing **DAVVAG Framework Application Development Architecture** as the authoritative source. Do not introduce Laravel, Yii, a separate authentication system, direct MySQL access or a separate upload framework.

---

# 1. Required Discovery Before Implementation

Before creating files:

1. Read the DAVVAG architecture context and relevant framework documentation.
2. Resolve the active tenant from the root runtime configuration.
3. Do not assume that `davvag-core/localhost` is the active tenant.
4. Search the active tenant for existing:

   * Map components or mapping plugins
   * Location or geocoding services
   * Rich-text or Markdown editors
   * Search components
   * Profile lookup services
   * Comment or review components
   * Notification workflows
   * File upload and image cropping components
   * Moderation or approval patterns
5. Reuse existing DAVVAG capabilities wherever possible.
6. Inspect similar existing applications to match their:

   * `app.json` structure
   * Component lifecycle
   * Service declarations
   * Schema format
   * Profile lookup
   * Media storage
   * Permission checks
   * Pagination
   * Route navigation
   * Error handling
   * Styling conventions

Report what existing functionality was found and what will be reused before implementing the app.

---

# 2. Application Capability Boundary

`travel-destinations` owns:

* Travel destination records
* Destination categories
* Destination attributes and amenities
* Destination location information
* Destination media relationships
* Hiking and camping-specific information
* Traveler submissions
* Submission moderation
* Reviews and ratings
* Comments
* Favorites
* User reports
* Nearby destination search
* Destination condition reports
* Public destination browsing

It must not create duplicate systems for:

* Authentication
* User accounts
* Profiles
* File uploading
* Image cropping
* Notifications
* AI providers
* General-purpose mapping, when a reusable DAVVAG capability already exists

---

# 3. User Roles and Access

## Anonymous public visitors

Anonymous users may:

* Browse published destinations
* Search destinations
* Use list and map views
* View destination details
* View public photos
* View approved reviews and comments
* View ratings
* View coordinates
* Open directions in a supported map provider
* Search for destinations near a selected location
* Share destination links

Anonymous users may not:

* Create destinations
* Upload images
* Add reviews
* Add comments
* Save favorites
* Report destinations
* Access unpublished content
* Access administration screens

Register only the intended public browsing routes in `anonymous.json`.

## Authenticated travelers

Authenticated travelers may:

* Access all public functionality
* Submit a new destination
* Edit their own submission while it is Draft or Returned for Changes
* Upload destination photos
* Add a review
* Add comments
* Mark reviews as helpful
* Save destinations to favorites
* Report inaccurate, unsafe or inappropriate content
* View their own submissions and moderation status
* Submit condition updates after visiting a location

Use the existing DAVVAG `profile` identity. Do not create a new traveler/person identity table.

Register traveler functionality only in the appropriate authenticated user group file, such as `web_user.json`.

## Administrators

Administrators may:

* Create, edit, publish, unpublish and archive destinations
* Review traveler submissions
* Approve, reject or return submissions for changes
* Moderate reviews, comments, photos and condition reports
* Mark destinations as verified
* Merge duplicate destinations
* Configure categories, amenities and destination attributes
* View reports and moderation queues
* Feature selected destinations
* Hide exact coordinates for environmentally sensitive places
* Maintain audit and moderation notes

Register administration access only in the correct administrative group file, such as `sysadmin.json`.

Do not rely only on group-file visibility. Sensitive service methods must perform backend authorization checks.

---

# 4. Destination Types

Support multiple categories per destination rather than limiting every place to one type.

Initial categories:

* Camping
* Hiking
* Stay
* Village
* Viewpoint
* Waterfall
* Beach
* Forest
* Mountain
* Lake or Reservoir
* Cultural Place
* Religious Place
* Wildlife or Nature Area

The `Stay` category must support subtypes:

* Hotel
* Cabana
* Guesthouse
* Homestay
* Campsite
* Eco Lodge
* Bungalow
* Hostel

Administrators must be able to add, edit, reorder, activate or deactivate categories without changing application code.

---

# 5. Destination Information

Each destination should support the following information where applicable.

## Basic information

* Destination name
* URL-safe slug
* Short summary
* Formatted description
* Destination categories
* Tags
* Publication status
* Verification status
* Featured status
* Primary language
* Alternative names
* Created by profile
* Approved by profile
* Publication date
* Last verified date

Use Markdown or an existing DAVVAG formatted-text editor for descriptions.

Store the original formatted content safely. Render only sanitized output. Do not render untrusted HTML directly through `innerHTML` or Vue HTML directives without sanitization.

## Location information

* Latitude
* Longitude
* Coordinate accuracy
* Exact or approximate location mode
* Province
* District
* Nearest town
* Village
* Address or location description
* Access road description
* Public transport instructions
* Parking information
* Distance from nearest town
* Last section of road condition
* Four-wheel-drive requirement
* Walking distance from parking
* External map place identifier when available
* Directions link
* Location verification status

Allow administrators and submitting travelers to:

* Search for a place
* Select a point on a map
* Drag the marker
* Enter latitude and longitude manually
* Use the current device location, with permission
* Reverse-geocode a selected point when a configured provider is available

Coordinates must be validated on the backend:

```text
latitude: -90 to 90
longitude: -180 to 180
```

## Location privacy

Support:

* Exact public location
* Approximate public location
* Hidden sensitive location
* Exact location visible only to approved users or administrators

This is needed for fragile ecosystems, unsafe areas, private land and destinations where publishing an exact campsite could cause environmental damage.

---

# 6. Type-Specific Information

## Camping information

* Tent suitability
* Maximum recommended group size
* Vehicle access
* Parking availability
* Water availability
* Drinking-water safety
* Toilet availability
* Shower availability
* Electricity availability
* Mobile network availability
* Fire allowed
* Cooking allowed
* Firewood availability
* Permit required
* Reservation required
* Camping fee
* Land ownership type
* Owner or caretaker contact
* Pet policy
* Waste disposal
* Wildlife risks
* Flood risk
* Strong wind exposure
* Suitable seasons
* Recommended months
* Emergency exit information

## Hiking information

* Trail start coordinates
* Trail end coordinates
* Route type:

  * Loop
  * Out and back
  * Point to point
* Difficulty:

  * Easy
  * Moderate
  * Hard
  * Expert
* Distance
* Estimated duration
* Minimum and maximum elevation
* Elevation gain
* Trail surface
* Trail markings
* Guide required
* Water sources
* River crossings
* Dangerous sections
* Mobile coverage
* Entrance fee
* Permit requirements
* Recommended start time
* GPX or GeoJSON trail file
* Trail condition
* Last trail-condition update

## Stay information

* Accommodation subtype
* Price range
* Number of guests
* Room or unit types
* Amenities
* Check-in and check-out information
* Contact number
* Website
* Booking link
* Meals available
* Parking
* Pet policy
* Accessibility
* Location owner verification

Do not build a complete booking and payment system in the first version. Keep booking fields ready for later integration with existing DAVVAG commerce, order and payment applications.

## Village information

* Village overview
* Culture and history
* Local experiences
* Local guides
* Local products
* Food experiences
* Community rules
* Photography restrictions
* Dress or behavior guidance
* Best visiting times
* Community contact
* Responsible tourism guidance

---

# 7. Amenities and Attributes

Create administrator-managed amenities and attributes.

Initial amenities should include:

* Drinking water
* Natural water source
* Toilet
* Shower
* Electricity
* Mobile signal
* Wi-Fi
* Parking
* Public transport
* Food nearby
* Shop nearby
* Medical help nearby
* Guide available
* Pet friendly
* Child friendly
* Wheelchair access
* Cooking area
* Campfire area
* Security
* Waste disposal
* Changing rooms
* Equipment rental

Use normalized relationships rather than adding a new Boolean field to the destination schema for every possible amenity.

---

# 8. Media Management

Reuse:

```text
davvag-tools
davvag-file-uploader
davvag-img-cropper
```

Each destination may have multiple images.

Each media relationship should support:

* Destination ID
* File or media reference
* Media type
* Caption
* Alternative text
* Credit or photographer
* Display order
* Cover-image flag
* Uploaded-by profile
* Moderation status
* Created timestamp

Required behavior:

1. Save the destination or draft first.
2. Upload images through the shared uploader.
3. Associate uploaded media with the destination.
4. Reload the saved destination details.
5. Allow image ordering and cover-image selection.
6. Validate allowed file types and sizes on the backend.
7. Never expose filesystem paths to the browser.

Traveler-uploaded images must remain pending until approved when moderation is enabled.

---

# 9. Public Search and Discovery

Create both list and map discovery modes.

## Search inputs

Users should be able to search by:

* Destination name
* Keyword
* Province
* District
* Nearest town
* Category
* Stay subtype
* Tags
* Amenities
* Rating
* Difficulty
* Distance from selected location
* Free or paid
* Permit requirement
* Vehicle accessibility
* Pet friendly
* Child friendly
* Accessibility
* Suitable month
* Currently open
* Verified destinations only

## Nearby search

Support searches such as:

* Camping places near me
* Hiking places within 10 km
* Cabanas near this waterfall
* Villages near the selected town
* Highly rated places near the current map area

Allow a controlled list of radius values, for example:

```text
1 km
5 km
10 km
25 km
50 km
100 km
```

Implement nearby search on the backend.

Use validated latitude, longitude, radius, page size and sorting values. Use a schema-defined raw query only when ordinary SOSSData queries cannot calculate geographic distance.

Do not accept SQL fragments, raw sort expressions or arbitrary query syntax from the browser.

Return the calculated distance in the search result.

Use an efficient bounding-box pre-filter before applying an exact geographic distance calculation when appropriate.

## Sorting

Support:

* Nearest
* Highest rated
* Most reviewed
* Recently added
* Recently verified
* Most viewed
* Featured
* Name

All large result sets must use pagination.

---

# 10. Map Architecture

First search for an existing DAVVAG map component or plugin.

Reuse it when it satisfies the requirements.

When no reusable map capability exists, create a small, reusable map component behind the app contract. A Leaflet-compatible implementation is acceptable, but the implementation must not hardcode the application permanently to one tile, geocoding or directions provider.

Create a provider abstraction for:

* Base map tiles
* Place search
* Reverse geocoding
* Directions links
* Optional place details

Provider settings and API credentials must be stored in protected server-side or tenant configuration.

The public map must support:

* Destination markers
* Marker clustering when many results are present
* Category-specific markers
* Current search area
* Map/list synchronization
* Clicking a marker to open a destination summary
* “Search this area”
* User-location marker after permission
* GeoJSON trail display
* Responsive mobile interaction
* Required provider attribution

Do not call a public geocoding service continuously on every keypress unless its usage rules allow it. Add debouncing, caching and provider-specific rate controls.

---

# 11. Destination Detail Screen

The public destination detail screen should display:

* Destination title
* Categories and tags
* Cover image
* Image gallery
* Sanitized formatted description
* Rating summary
* Review count
* Verification state
* Last verified date
* Map
* Coordinates, subject to privacy settings
* Directions button
* Distance from the viewer when location permission exists
* Amenities
* Access instructions
* Best visiting period
* Fees and permits
* Camping, hiking, stay or village-specific information
* Weather summary when configured
* Sunrise and sunset when configured
* Safety warnings
* Current condition reports
* Reviews
* Comments
* Nearby destinations
* Save to favorites
* Share link
* Report incorrect information

Add a clear notice that outdoor conditions can change and users must verify weather, access, permits and safety before traveling.

---

# 12. Traveler Destination Submission

Create a guided submission form.

Suggested steps:

1. Basic information
2. Category selection
3. Location and map
4. Type-specific information
5. Amenities
6. Access and safety
7. Photos
8. Preview
9. Submit for review

Submission statuses:

```text
Draft
Pending Review
Returned for Changes
Approved
Rejected
Published
Archived
```

Travelers must be able to save drafts.

When submitted:

* Validate all required information
* Record the submitting profile
* Lock fields that should not change during review
* Place the record into the moderation queue
* Optionally trigger an existing DAVVAG notification or workflow
* Prevent immediate public publication unless the user has an explicitly authorized publisher role

Administrators must be able to provide a rejection reason or requested changes.

---

# 13. Reviews and Ratings

Authenticated travelers may add one active review per destination, but they may update their own review after another visit.

Review fields:

* Destination ID
* Reviewer profile ID
* Overall rating from 1 to 5
* Review title
* Review text
* Visit date
* Traveler type
* Would visit again
* Condition at visit
* Optional photos
* Moderation status
* Helpful count
* Created date
* Updated date

Optional category ratings:

* Scenery
* Cleanliness
* Safety
* Accessibility
* Facilities
* Accuracy of destination information
* Value

Requirements:

* Calculate destination rating aggregates on the backend.
* Do not trust a rating average supplied by the browser.
* Prevent duplicate active reviews by the same profile.
* Allow travelers to edit or remove their own reviews.
* Allow administrators to moderate reviews.
* Do not show rejected or pending reviews publicly.
* Add helpful and report actions.
* Add in-flight request locks to all review actions.

---

# 14. Comments

Comments are separate from reviews.

A comment does not require a rating and may be used for:

* Questions
* Access updates
* Advice
* Clarification
* Local information

Support:

* Destination comments
* One-level replies where practical
* Edit and delete own comment
* Administrator moderation
* Report comment
* Pending or approved status
* Basic anti-spam controls
* Pagination

Do not implement unlimited recursive comment nesting in the first version.

---

# 15. Condition Reports

Add short, time-sensitive condition updates.

Condition types:

* Road blocked
* Trail closed
* Heavy rain
* Flooding
* Landslide
* Strong wind
* Fire risk
* Unsafe water
* Construction
* Entrance closed
* Permit change
* High crowd level
* Mobile signal unavailable
* Campsite unavailable
* General update

Each report should include:

* Destination
* Report type
* Description
* Reporter profile
* Observed date and time
* Expiry date
* Optional photo
* Moderation state
* Confirmation count
* Dispute count

Condition reports should expire or be marked outdated rather than remaining permanently active.

Administrators must be able to pin official warnings.

---

# 16. Favorites and Lists

Authenticated travelers should be able to:

* Save a destination
* Remove a saved destination
* View saved destinations

Design the schema so named travel lists can be added later, such as:

* Weekend Trips
* Camping Plans
* Places to Visit
* Family Trips

The first release may provide one default Favorites list.

---

# 17. User Reports and Moderation

Users must be able to report:

* Incorrect location
* Duplicate destination
* Closed destination
* Private property
* Unsafe destination
* Misleading description
* Inappropriate image
* Inappropriate review or comment
* Environmental concern
* Spam

Report fields:

* Entity type
* Entity ID
* Reporter profile
* Reason
* Description
* Status
* Assigned moderator
* Resolution notes
* Created date
* Resolved date

Administrative moderation screens must include:

* Pending destination submissions
* Pending photos
* Pending reviews
* Pending comments
* Active condition reports
* User reports
* Suspected duplicate destinations

---

# 18. Suggested Schema Namespaces

Confirm the active tenant’s existing naming conventions before finalizing names.

Suggested namespaces:

```text
travel_destination
travel_destination_category
travel_destination_category_link
travel_destination_amenity
travel_destination_amenity_link
travel_destination_media
travel_destination_review
travel_destination_review_helpful
travel_destination_comment
travel_destination_favorite
travel_destination_submission_log
travel_destination_condition
travel_destination_report
```

Use schema relationships for stable direct references.

Do not define DAVVAG framework system columns manually.

Use system-created audit fields such as `syscreated`, `sysupdated`, `syscreatedby` and `syslastupdatedby` where available.

Do not create duplicate profile or user fields containing full user information. Store the existing profile identity reference.

---

# 19. Suggested Components and Routes

Match existing DAVVAG naming conventions after inspection.

Suggested UI components:

```text
destination-explorer
destination-map
destination-detail
destination-form
my-destinations
my-favorites
review-editor
comment-section
condition-reporter
admin-destination-list
admin-destination-editor
admin-moderation
admin-categories
admin-amenities
```

Suggested routes:

```text
/
#/app/travel-destinations
#/app/travel-destinations/search
#/app/travel-destinations/map
#/app/travel-destinations/place/{slug-or-id}
#/app/travel-destinations/submit
#/app/travel-destinations/my-submissions
#/app/travel-destinations/favorites
#/app/travel-destinations/admin
#/app/travel-destinations/admin/moderation
#/app/travel-destinations/admin/categories
#/app/travel-destinations/admin/amenities
```

Use the DAVVAG shell routing component.

Test routes inside both supported public and admin docks.

Do not hardcode component service URLs in frontend code.

---

# 20. Suggested Service Contracts

Create service handlers following existing DAVVAG naming conventions and HTTP method mapping.

Possible service operations:

## Public read services

* SearchDestinations
* GetDestination
* GetNearbyDestinations
* GetDestinationReviews
* GetDestinationComments
* GetDestinationConditions
* GetCategories
* GetAmenities
* GetMapResults

## Authenticated traveler services

* SaveDestinationDraft
* SubmitDestination
* UpdateOwnSubmission
* UploadDestinationMedia
* SaveReview
* DeleteOwnReview
* SaveComment
* DeleteOwnComment
* SaveFavorite
* RemoveFavorite
* SubmitConditionReport
* SubmitContentReport
* MarkReviewHelpful
* GetMySubmissions
* GetMyFavorites

## Administrator services

* SaveDestination
* PublishDestination
* UnpublishDestination
* ArchiveDestination
* ApproveSubmission
* RejectSubmission
* ReturnSubmission
* ApproveMedia
* ModerateReview
* ModerateComment
* ModerateCondition
* ResolveReport
* MergeDuplicateDestination
* SaveCategory
* SaveAmenity
* GetModerationQueue

Every service must:

* Validate input
* Authorize the action
* Use the active profile where identity is required
* Use SOSSData
* Return a stable response contract
* Avoid leaking internal paths or stack traces
* Limit public result sizes
* Apply appropriate view-object filtering
* Validate ownership for user edits
* Lock sensitive state transitions on the backend

---

# 21. Security Requirements

Implement the following:

* Backend validation for every untrusted input
* Authorization for every write operation
* Ownership checks
* Server-side rating aggregation
* Sanitized formatted content
* Safe URL validation
* File type and file size validation
* Coordinate validation
* Allowed-value validation for categories, status, radius and sort
* Pagination limits
* Rate controls for public submissions and comments
* Protection from duplicate submissions
* No raw SQL from browser input
* No API keys in frontend JavaScript
* No unrestricted public write endpoints
* No exact sensitive coordinates in public responses
* No public access to drafts or pending moderation records
* User-safe error messages
* Server-side logging without exposing secrets

All service-triggering buttons must remain locked for the complete lifetime of the associated request and unlock on success or failure.

---

# 22. Rich Text and Markdown Safety

Descriptions, reviews and comments are untrusted content.

Use one of these approaches:

1. Store Markdown and render it through a safe Markdown renderer with a strict allowlist.
2. Use an existing DAVVAG rich-text editor and sanitize the submitted HTML on the backend and before DOM rendering.

Allow only safe formatting such as:

* Paragraphs
* Bold
* Italic
* Headings
* Lists
* Safe links
* Blockquotes

Disallow:

* Scripts
* Inline event handlers
* Iframes unless explicitly approved
* Embedded JavaScript URLs
* Unsafe styles
* Arbitrary HTML
* Untrusted executable embeds

---

# 23. Weather and Safety Integration

Create weather as an optional provider integration, not an authoritative part of the destination record.

When configured, destination details may display:

* Current temperature
* Rain probability
* Expected rainfall
* Wind speed and gusts
* Visibility
* Sunrise
* Sunset
* Elevation
* Short forecast
* Severe-condition warning

Store provider configuration in protected server-side configuration.

Cache responses for a reasonable period.

The app must still work when the weather provider is unavailable.

Weather data must display its observation or forecast time and provider attribution.

---

# 24. Responsible Travel

Include configurable responsible-travel information.

Examples:

* Check regulations and permits before traveling
* Check weather and trail conditions
* Do not leave waste
* Camp only where permitted
* Avoid damaging vegetation
* Respect wildlife
* Respect local communities
* Do not reveal restricted or environmentally fragile locations
* Follow fire restrictions
* Carry sufficient water and emergency supplies

Allow administrators to attach destination-specific responsible-travel instructions.

---

# 25. MVP Priority

Implement the first release in this order:

## Phase 1 — Required MVP

1. Active tenant discovery
2. App descriptor and registration
3. Destination schemas
4. Admin destination management
5. Traveler submission and moderation
6. Multiple categories
7. Location picker with coordinates
8. Public list and map search
9. Nearby search
10. Destination detail page
11. Shared media uploader
12. Reviews and ratings
13. Comments
14. Favorites
15. Reports
16. Permissions and validation
17. Pagination
18. Route and service tests

## Phase 2 — Recommended enhancements

* Condition reports
* Weather integration
* GPX and GeoJSON hiking routes
* Offline saved destination information
* Named travel lists
* Verified traveler visits
* Local guide profiles
* Advanced map clustering
* Availability and booking integration
* Notifications
* Multi-language content
* Search suggestions
* Featured collections
* Trip planning
* Personal recommendations

## Phase 3 — Advanced outdoor features

* Offline maps
* Trail recording
* Wrong-turn alerts
* Live trip sharing
* Emergency contact sharing
* User-created routes
* Route elevation profiles
* Community trail heatmaps
* Permit and closure alerts
* AI-assisted tagging and review summarization through `ai-agent-creator`

AI must not approve destinations, authorize actions, determine safety or replace deterministic validation.

---

# 26. Testing Requirements

Perform and document actual tests for:

## Static validation

* Every JSON file parses
* Every PHP file passes syntax checking
* App and component paths exist
* Service namespace and class names match descriptors
* Service handler names match HTTP methods
* Startup component exists
* All `onLoad` components exist
* Dependencies contain no blank values
* Schema namespaces match service usage

## Framework routes

Test:

```text
GET /components/object/appdescriptor/travel-destinations
GET /components/travel-destinations/{component}/object?object=desc
GET /components/travel-destinations/{component}/file/script.js
GET /components/travel-destinations/{component}/file/partial.html
GET /components/object/apps
```

Test every implemented service route using the correct HTTP method.

## Functional tests

* Anonymous user can browse published destinations
* Anonymous user cannot write content
* Traveler can save a draft
* Traveler can submit a destination
* Traveler cannot publish directly
* Traveler can edit only their own eligible submission
* Administrator can approve and publish
* Pending content is not publicly visible
* Nearby search calculates reasonable distances
* Invalid coordinates are rejected
* Invalid radius and sorting values are rejected
* Duplicate active reviews are prevented
* Rating aggregates are correct
* User cannot edit another person’s review or comment
* Hidden coordinates are not leaked
* Image uploads use the shared uploader
* Pagination works
* Empty search results are handled
* Service errors unlock buttons
* App works in supported public and admin docks

Do not claim that a test passed unless it was actually executed.

---

# 27. Final Implementation Report

After implementation, report:

* Active tenant resolved
* Existing applications and components reused
* Files created
* Files modified
* Components created
* Services created
* Schemas created
* Workflows created or reused
* Dependencies declared
* Routes registered
* Group files modified
* Security decisions
* Map provider strategy
* Weather provider strategy
* Tests executed
* Test results
* Known limitations
* Recommended next development phase

Preserve all existing entries when updating:

```text
tenant.json
anonymous.json
web_user.json
sysadmin.json
```

Merge the new registration into these files. Never replace existing files with minimal generated versions.

Bump application and component versions after resource or descriptor changes to avoid stale cached resources.
