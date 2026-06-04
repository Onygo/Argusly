<x-errors.layout
    code="403"
    label="Access orbit"
    title="This door recognized you, then asked for a second opinion."
    description="Argusly can see there is a visitor at the console, but the current workspace clearance did not line up with the requested signal. Very formal. Slightly dramatic."
    note="Permission found the velvet rope and decided this was its moment."
    primary-label="Go to dashboard"
    :primary-href="route('dashboard')"
    secondary-label="Open admin"
    :secondary-href="route('admin.overview')"
/>
