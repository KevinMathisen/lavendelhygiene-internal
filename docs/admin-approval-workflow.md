New registration
    pending, no Tripletex writes

Admin page resolves company
    existing ID found:
        show locked suggested value
        Save ID available
        Create hidden
        Approve disabled

    no existing ID:
        editable ID input
        Save ID available
        Create available
        Approve disabled

Sales clicks Save ID
    ID validated and persisted
    linked action runs sync_user()
    page reloads
    ID field is locked
    Save ID removed
    Approve enabled

Sales clicks Create in Tripletex
    customer created and linked
    linked action runs sync_user()
    page reloads
    created ID is locked
    Approve enabled

Sales clicks Approve
    backend confirms saved ID exists
    role/status updated
    approval email sent