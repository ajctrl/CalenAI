import unittest

from scripts.compatibility import plan


# Includes an unreleased major, a maintenance release that is still planned,
# and EOL releases bridging the app's declared range to a future major.
SCHEDULE = """
## Currently supported versions

| Version code | Current version | Next version
| --- | --- | ---
| **49** | **49.0.2** (2029-01-01) | **49.0.3** (2029-02-01)

## Nextcloud 50 (draft)

| Version code | Current version |
| --- | --- |
| 50 | 50.0.0 |

## EOL Versions

| Version code | Last version |
| --- | --- |
| 48 | 48.0.14 (2028-12-01) |
| 47 | 47.1.12 (2028-08-01) |
"""


class CompatibilityPlanTest(unittest.TestCase):
    def test_checks_each_major_between_declared_range_and_supported_table(self):
        result = plan(SCHEDULE, ("1.2.9", 47, 47))
        self.assertEqual(result["matrix"], ["47.1.12", "48.0.14", "49.0.2"])
        self.assertEqual((result["minimum"], result["maximum"]), (47, 49))
        self.assertEqual(result["next_version"], "1.2.10")

    def test_missing_bridge_blocks_expansion(self):
        with self.assertRaisesRegex(ValueError, "48"):
            plan(SCHEDULE.replace("| 48 | 48.0.14 (2028-12-01) |\n", ""), ("1.2.9", 47, 47))

    def test_prerelease_in_current_column_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "Current version"):
            plan(SCHEDULE.replace("49.0.2", "49.0.2RC1"), ("1.2.9", 49, 49))

    def test_existing_range_keeps_its_version(self):
        self.assertEqual(plan(SCHEDULE, ("1.2.9", 47, 49))["next_version"], "1.2.9")
